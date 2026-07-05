#!/usr/bin/env bash
set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"

info()  { printf "${CYAN}▸${NC} %s\n" "$*"; }
ok()    { printf "${GREEN}✓${NC} %s\n" "$*"; }
warn()  { printf "${YELLOW}⚠${NC} %s\n" "$*"; }
error() { printf "${RED}✗${NC} %s\n" "$*" >&2; }

# Carica .env
if [[ ! -f "${PROJECT_ROOT}/.env" ]]; then
    error ".env non trovato. Copia .env.example in .env e configuralo."
    exit 1
fi
set -a
source "${PROJECT_ROOT}/.env"
set +a

if [[ -z "${ENV:-}" ]]; then
    error "ENV non definito in .env (stage|prod)"
    exit 1
fi

PROJECT_NAME="ildeposito-${ENV}"
export COMPOSE_PROJECT_NAME="${PROJECT_NAME}"
COMPOSE="docker compose --project-directory ${PROJECT_ROOT}"

# La dir dei file Drupal è sul bind mount del codebase (non sul volume /mnt/files
# gestito da Wodby), quindi Wodby non ne corregge mai owner/permessi all'avvio.
# PHP-FPM gira come www-data (uid 82) e drush come wodby (membro del gruppo
# www-data): allineiamo owner a www-data + setgid, così scrivono entrambi e le
# sottocartelle create dalle migrazioni ereditano il gruppo. Idempotente.
fix_files_permissions() {
    local files_dir="/var/www/html/web/sites/default/files"
    info "Allineo permessi ${files_dir} (www-data)..."
    for i in $(seq 1 10); do
        if ${COMPOSE} exec -T -u root php sh -c "
            chown -R www-data:www-data '${files_dir}' &&
            find '${files_dir}' -type d -exec chmod 2775 {} + &&
            find '${files_dir}' -type f -exec chmod 664 {} +" 2>/dev/null; then
            ok "Permessi files allineati"
            return 0
        fi
        info "php non ancora pronto, riprovo... (${i}/10)"
        sleep 3
    done
    warn "Impossibile allineare i permessi di ${files_dir} (php non raggiungibile)"
}

# Il builder Astro parte a freddo su una rete separata (app-internal) e
# raggiunge nginx solo tramite l'alias drupal-api: nessuno step precedente
# della pipeline (drush/composer girano via exec diretto nel container php,
# bypassando nginx) verifica che nginx sia davvero pronto dopo un
# force-recreate. Senza questa attesa, il prerendering può incappare in un
# nginx ancora in avvio (o in restart-loop da autoheal) e ricevere
# "SocketError: other side closed" a metà fetch.
wait_for_nginx_healthy() {
    local nginx_container
    nginx_container="$(${COMPOSE} ps -q nginx)"
    if [[ -z "${nginx_container}" ]]; then
        warn "Container nginx non trovato, salto l'attesa di readiness"
        return 0
    fi
    info "Attendo che nginx sia healthy..."
    for i in $(seq 1 20); do
        local status
        status="$(docker inspect --format='{{.State.Health.Status}}' "${nginx_container}" 2>/dev/null || echo "unknown")"
        if [[ "${status}" == "healthy" ]]; then
            ok "nginx healthy"
            return 0
        fi
        info "nginx non ancora healthy (${status}), riprovo... (${i}/20)"
        sleep 3
    done
    warn "nginx non è diventato healthy in tempo, procedo comunque"
}

cmd_up() {
    local extra_flags="${1:-}"
    info "Avvio ambiente ${ENV} (${PROJECT_NAME})..."
    local internal_net="ildeposito-${ENV}-internal"
    docker network inspect "${internal_net}" &>/dev/null \
        || { info "Creazione rete ${internal_net}..."; docker network create "${internal_net}"; }
    ${COMPOSE} pull --quiet
    ${COMPOSE} up -d ${extra_flags}
    # Restart mirato (non --force-recreate): 'up -d' ricrea da sé i container
    # il cui config/immagine cambia (es. bump versione Wodby in .env), ma un
    # git pull sul bind mount non tocca config/immagine, quindi senza questo
    # restart l'opcache di PHP servirebbe bytecode del deploy precedente.
    # A differenza di --force-recreate, un restart non distrugge il
    # container: niente perdita della cache Composer né riavvio di
    # mariadb/redis, che restano quindi disponibili durante il deploy.
    info "Restart php (refresh opcache)..."
    ${COMPOSE} restart php
    fix_files_permissions
    ok "Ambiente ${ENV} avviato"
    info "Backend:  https://admin-${ENV}.ildeposito.org"
    info "Frontend: https://${ENV}.ildeposito.org"
    echo ""
    if ! docker volume inspect "${PROJECT_NAME}_frontend_output" &>/dev/null || \
       [ -z "$(docker run --rm -v "${PROJECT_NAME}_frontend_output:/data" alpine ls /data/current 2>/dev/null)" ]; then
        warn "Nessuna build frontend trovata. Esegui: ./ildeposito.sh build-frontend"
    fi
}

cmd_down() {
    info "Rimozione ambiente ${ENV} (${PROJECT_NAME})..."
    ${COMPOSE} down
    ok "Ambiente ${ENV} rimosso"
}

cmd_stop() {
    info "Arresto ambiente ${ENV} (${PROJECT_NAME})..."
    ${COMPOSE} stop
    ok "Ambiente ${ENV} arrestato"
}

cmd_restart() {
    info "Riavvio ambiente ${ENV} (${PROJECT_NAME})..."
    ${COMPOSE} restart
    ok "Ambiente ${ENV} riavviato"
}

cmd_build_frontend() {
    local mode="${1:-full}"  # full (contenuti+pdf) | content (no pdf) | pdf (solo pdf, in-place)
    case "${mode}" in
        full|content|pdf) ;;
        *) error "Modalità build-frontend sconosciuta: ${mode} (usa: full|content|pdf)"; exit 1 ;;
    esac

    info "Build frontend Astro [${ENV}] modalità: ${mode}..."

    info "Rebuild immagine astro-builder..."
    ${COMPOSE} build astro-builder

    wait_for_nginx_healthy

    info "Avvio build..."
    ${COMPOSE} run --rm astro-builder sh docker-entrypoint.sh "${mode}"

    if [[ "${mode}" != "pdf" ]]; then
        info "Ricarica configurazione nginx..."
        ${COMPOSE} exec frontend-web nginx -s reload

        info "Riavvio server SSR (frontend-api)..."
        ${COMPOSE} restart frontend-api
    fi

    ok "Build frontend completata (${mode})"
    info "Il sito è live su https://${ENV}.ildeposito.org"
}

cmd_drush() {
    ${COMPOSE} exec -T php drush -r /var/www/html/web "$@"
}

# Import delle migrazioni nell'ordine di dipendenza definito in
# backend/script/migrate-import.sh (immagini/media prima, poi tassonomie,
# autori, canti e relazioni, eventi, traduzioni). Eventuali flag extra
# (es. --update) vengono passati a ogni singola migrazione.
cmd_migrate() {
    local -a migrations=(
        immagini
        media
        termini_localizzazioni
        termini_lingue
        termini_periodi
        termini_tags
        termini_tematiche
        autori
        canti
        canti_correlati
        autori_correlati
        eventi
        traduzioni
    )
    info "Import migrazioni [${ENV}] (${#migrations[@]} migrazioni)..."
    # immagini/media scrivono in public://: allinea prima i permessi.
    fix_files_permissions
    for migration in "${migrations[@]}"; do
        info "Migrazione: ${migration}"
        cmd_drush mim "${migration}" "$@"
    done
    ok "Import migrazioni completato"
}

cmd_composer() {
    ${COMPOSE} exec -T php composer --working-dir=/var/www/html "$@"
}

cmd_exec() {
    local service="$1"
    shift
    ${COMPOSE} exec "${service}" "$@"
}

cmd_shell() {
    local service="${1:-php}"
    ${COMPOSE} exec "${service}" sh
}

cmd_logs() {
    ${COMPOSE} logs -f "$@"
}

cmd_ps() {
    ${COMPOSE} ps
}

# Verifica link interni sul build statico servito da nginx. I file stanno nel
# volume frontend_output (current/client): li analizziamo con un container
# node usa-e-getta montando lo stesso script usato in locale.
cmd_linkcheck() {
    local vol="${PROJECT_NAME}_frontend_output"
    local script="${PROJECT_ROOT}/frontend/scripts/linkcheck.mjs"
    if ! docker volume inspect "${vol}" &>/dev/null; then
        error "Volume ${vol} non trovato. Esegui prima: ./ildeposito.sh build-frontend"
        exit 1
    fi
    if [[ " $* " == *" --check-external "* ]]; then
        info "Verifica link interni ed esterni sul build statico [${ENV}]..."
    else
        info "Verifica link interni sul build statico [${ENV}]..."
    fi
    docker run --rm \
        -v "${vol}:/output:ro" \
        -v "${script}:/linkcheck.mjs:ro" \
        node:22-alpine node /linkcheck.mjs /output/current/client "$@"
}

usage() {
    cat <<EOF
${BOLD}Uso:${NC} ./ildeposito.sh <comando>

${BOLD}Ambiente:${NC} ${ENV} (${PROJECT_NAME})

${BOLD}Comandi:${NC}
  up                Avvia l'ambiente
  down              Rimuovi containers e reti
  stop              Arresta l'ambiente
  restart           Riavvia l'ambiente
  build-frontend            Build Astro completa (contenuti + pdf) + deploy zero-downtime
  build-frontend-content    Build Astro solo contenuti (no pdf) + deploy zero-downtime
  build-frontend-pdf        Rigenera solo i pdf, in-place nella release corrente
  drush <args>      Esegui comando drush
  migrate [flags]   Importa tutte le migrazioni (ordine di dipendenza)
  composer <args>   Esegui comando composer
  exec <srv> <cmd>  Esegui comando in un container
  shell [servizio]  Shell nel container (default: php)
  logs [servizio]   Visualizza i log
  ps                Lista dei container attivi
  linkcheck         Verifica link interni rotti nel build statico
                      --check-external verifica anche i link esterni via HTTP (YouTube via oEmbed)
                      --timeout=ms --concurrency=n (default 8000ms, 8 richieste parallele)
EOF
}

case "${1:-}" in
    up)              shift; cmd_up "$@" ;;
    down)            cmd_down ;;
    stop)            cmd_stop ;;
    restart)         cmd_restart ;;
    build-frontend)          shift; cmd_build_frontend full ;;
    build-frontend-content)  shift; cmd_build_frontend content ;;
    build-frontend-pdf)      shift; cmd_build_frontend pdf ;;
    drush)           shift; cmd_drush "$@" ;;
    migrate)         shift; cmd_migrate "$@" ;;
    composer)        shift; cmd_composer "$@" ;;
    exec)            shift; cmd_exec "$@" ;;
    shell)           shift; cmd_shell "$@" ;;
    logs)            shift; cmd_logs "$@" ;;
    ps)              cmd_ps ;;
    linkcheck)       shift; cmd_linkcheck "$@" ;;
    -h|--help|help)  usage ;;
    "")              usage; exit 1 ;;
    *)               error "Comando sconosciuto: $1"; usage; exit 1 ;;
esac
