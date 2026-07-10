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
            mkdir -p '${files_dir}' &&
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
    local service="${1:-nginx}"
    local nginx_container
    nginx_container="$(${COMPOSE} ps -q "${service}")"
    if [[ -z "${nginx_container}" ]]; then
        warn "Container ${service} non trovato, salto l'attesa di readiness"
        return 0
    fi
    info "Attendo che ${service} sia healthy..."
    for i in $(seq 1 20); do
        local status
        status="$(docker inspect --format='{{.State.Health.Status}}' "${nginx_container}" 2>/dev/null || echo "unknown")"
        if [[ "${status}" == "healthy" ]]; then
            ok "${service} healthy"
            return 0
        fi
        info "${service} non ancora healthy (${status}), riprovo... (${i}/20)"
        sleep 3
    done
    warn "${service} non è diventato healthy in tempo, procedo comunque"
}

# File di config nginx montati come bind mount di singolo file in
# frontend-web (vedi frontend/compose.${ENV}.yml). git pull li sostituisce
# con un rename atomico (nuovo inode): il mount del container resta
# agganciato all'inode di quando è stato creato, quindi se questi file
# cambiano da un deploy all'altro un semplice `nginx -s reload` continua
# silenziosamente a servire la config precedente — serve un force-recreate
# del container per un mount nuovo (vedi cmd_build_frontend).
NGINX_CONF_FILES=(
    "${PROJECT_ROOT}/frontend/nginx.conf"
    "${PROJECT_ROOT}/frontend/nginx-ratelimit.conf"
    "${PROJECT_ROOT}/frontend/nginx-log-formats.conf"
    "${PROJECT_ROOT}/frontend/nginx-security-headers.conf"
    "${PROJECT_ROOT}/frontend/nginx-realip.conf.template"
)
nginx_conf_hash() {
    cat "${NGINX_CONF_FILES[@]}" | md5sum | cut -d' ' -f1
}

# Valida i file di config nginx in un container "usa e getta" invece che con
# `exec` in quello già in esecuzione: per il motivo spiegato sopra, un exec
# nel container esistente validerebbe la vecchia config se questa è cambiata
# dall'ultima volta che è stato creato. Un container temporaneo (`run --rm`)
# monta invece i file allo stato attuale. Replica a mano il comando
# dell'immagine (envsubst di realip.conf.template) perché `run` con un
# comando esplicito sovrascrive il `command:` del servizio.
validate_nginx_config() {
    info "Verifica configurazione nginx (container temporaneo)..."
    if ${COMPOSE} run --rm -T --no-deps frontend-web sh -c "
        envsubst '\$CADDY_TRUSTED_SUBNET' < /etc/nginx/templates/realip.conf.template > /etc/nginx/conf.d/realip.conf &&
        nginx -t
    "; then
        ok "Configurazione nginx valida"
    else
        error "Configurazione nginx NON valida — deploy annullato, nginx resta sulla release precedente"
        exit 1
    fi
}

# Domini pubblici: stage segue il pattern admin-${ENV}/${ENV}, prod usa domini
# dedicati (admin.ildeposito.org, FRONTEND_DOMAIN) senza prefisso ambiente.
public_backend_url() {
    [[ "${ENV}" == "prod" ]] && echo "https://admin.ildeposito.org" || echo "https://admin-${ENV}.ildeposito.org"
}
public_frontend_url() {
    [[ "${ENV}" == "prod" ]] && echo "https://${FRONTEND_DOMAIN:-www.ildeposito.org}" || echo "https://${ENV}.ildeposito.org"
}

cmd_up() {
    local extra_flags="${1:-}"
    info "Avvio ambiente ${ENV} (${PROJECT_NAME})..."
    local internal_net="ildeposito-${ENV}-internal"
    docker network inspect "${internal_net}" &>/dev/null \
        || { info "Creazione rete ${internal_net}..."; docker network create "${internal_net}"; }
    local log_vol="ildeposito-${ENV}-404-log"
    docker volume inspect "${log_vol}" &>/dev/null \
        || { info "Creazione volume ${log_vol}..."; docker volume create "${log_vol}"; }
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
    info "Backend:  $(public_backend_url)"
    info "Frontend: $(public_frontend_url)"
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
    local mode="${1:-full}"  # full (contenuti+pdf) | content (no pdf) | pdf (solo pdf, in-place) | canzonieri (solo canzonieri, in-place)
    case "${mode}" in
        full|content|pdf|canzonieri) ;;
        *) error "Modalità build-frontend sconosciuta: ${mode} (usa: full|content|pdf|canzonieri)"; exit 1 ;;
    esac

    info "Build frontend Astro [${ENV}] modalità: ${mode}..."

    info "Rebuild immagine astro-builder..."
    ${COMPOSE} build astro-builder

    wait_for_nginx_healthy

    info "Avvio build..."
    ${COMPOSE} run --rm astro-builder sh docker-entrypoint.sh "${mode}"

    if [[ "${mode}" != "pdf" && "${mode}" != "canzonieri" ]]; then
        # frontend-web può essere stato ricreato pochi secondi prima da
        # './ildeposito.sh up' (es. dopo un pull immagine): il suo healthcheck
        # ha start_period 10s + 3 retries da 30s, quindi può risultare ancora
        # "restarting" (autoheal) quando arriviamo qui, facendo fallire
        # l'exec sottostante con "Container is restarting, wait until the
        # container is running" pur essendo la config nginx valida.
        wait_for_nginx_healthy frontend-web

        # I redirect legacy generati a build time (_redirects.conf, vedi
        # generate-redirects.mjs) possono in teoria contenere una riga
        # malformata sfuggita alla validazione Drupal: mai ricaricare nginx
        # senza aver prima verificato la config, e mai silenziare l'esito.
        validate_nginx_config

        local nginx_hash_file="${PROJECT_ROOT}/.nginx-conf.hash"
        local new_nginx_hash
        new_nginx_hash="$(nginx_conf_hash)"
        if [[ "$(cat "${nginx_hash_file}" 2>/dev/null)" != "${new_nginx_hash}" ]]; then
            # Config cambiata dall'ultimo deploy: un reload da solo
            # servirebbe ancora i file vecchi (vedi commento su
            # NGINX_CONF_FILES) — serve un container nuovo, con un mount nuovo.
            info "Config nginx cambiata dall'ultimo deploy: ricreo frontend-web..."
            ${COMPOSE} up -d --force-recreate frontend-web
            wait_for_nginx_healthy frontend-web
        else
            info "Ricarica configurazione nginx..."
            ${COMPOSE} exec frontend-web nginx -s reload
        fi
        echo "${new_nginx_hash}" > "${nginx_hash_file}"

        info "Riavvio server SSR (frontend-api)..."
        ${COMPOSE} restart frontend-api
    fi

    ok "Build frontend completata (${mode})"
    info "Il sito è live su $(public_frontend_url)"
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

# Allinea stage a prod: DB + file caricati. Prod e stage vivono sullo stesso
# host (vedi working-directory in deploy-{stage,prod}.yml), quindi si legge
# direttamente il backup.sql di prod da filesystem, senza scp. Pensato per
# girare da cron ogni notte: nessuna conferma interattiva.
cmd_allinea_prod() {
    if [[ "${ENV}" != "stage" ]]; then
        error "allinea-prod va eseguito solo in ambiente stage (ENV=${ENV})"
        exit 1
    fi

    local prod_root
    prod_root="$(cd "${PROJECT_ROOT}/../prod" && pwd)"
    local prod_dump="${prod_root}/backup/backup.sql"
    local prod_files="${prod_root}/backend/web/sites/default/files/"
    local stage_files="${PROJECT_ROOT}/backend/web/sites/default/files/"

    if [[ ! -f "${prod_dump}" ]]; then
        error "Dump non trovato: ${prod_dump} (verifica il cron di backup su prod)"
        exit 1
    fi

    info "Svuoto il database stage..."
    cmd_drush sql:drop -y
    ok "Database stage svuotato"

    info "Importo il dump di prod (${prod_dump})..."
    cmd_drush sql:cli < "${prod_dump}"
    ok "Dump importato"

    info "Sincronizzo sites/default/files da prod (rsync, con --delete)..."
    rsync -a --delete "${prod_files}" "${stage_files}"
    ok "File sincronizzati"

    fix_files_permissions

    info "Database update..."
    cmd_drush updatedb -y
    ok "Database aggiornato"

    info "Import configurazione..."
    cmd_drush config:import -y
    ok "Configurazione importata"

    info "Cache rebuild..."
    cmd_drush cache:rebuild
    ok "Cache pulita"

    ok "Allineamento da prod completato, avvio build frontend..."
    cmd_build_frontend full
}

# Backup completo dell'applicazione: dump del database (esclude schema+dati
# nulla, mantiene solo lo schema vuoto di tabelle cache_*/cachetags e
# search_api_db_* così il DB resta importabile subito, senza aspettare un
# cache:rebuild o un reindex) + dump della directory immagini. Entrambi in
# bz2 in backup/ildeposito/, retention 30 giorni su base temporale (mtime).
cmd_backup() {
    local backup_dir="${PROJECT_ROOT}/backup/ildeposito"
    mkdir -p "${backup_dir}"
    local timestamp
    timestamp="$(date +%Y%m%d-%H%M%S)"

    info "Dump database (schema vuoto per cache*/search_api_db_*)..."
    local db_sql_container="/var/backup_migrate/ildeposito/db-${timestamp}.sql"
    local db_dump="${backup_dir}/db-${timestamp}.sql.bz2"
    cmd_drush sql:dump \
        --structure-tables-list=cache*,search_api_db_* \
        --result-file="${db_sql_container}"
    ${COMPOSE} exec -T php bzip2 "${db_sql_container}"
    ok "Dump database: backup/ildeposito/db-${timestamp}.sql.bz2 ($(du -h "${db_dump}" | cut -f1))"

    info "Dump directory immagini..."
    local immagini_dir="${PROJECT_ROOT}/backend/web/sites/default/files/immagini"
    if [[ -d "${immagini_dir}" ]]; then
        local files_dump="${backup_dir}/immagini-${timestamp}.tar.bz2"
        tar -cjf "${files_dump}.tmp" -C "$(dirname "${immagini_dir}")" "$(basename "${immagini_dir}")"
        mv "${files_dump}.tmp" "${files_dump}"
        ok "Dump immagini: backup/ildeposito/immagini-${timestamp}.tar.bz2 ($(du -h "${files_dump}" | cut -f1))"
    else
        warn "Directory ${immagini_dir} non trovata, skip"
    fi

    info "Applico retention (30 giorni)..."
    find "${backup_dir}" -name "db-*.sql.bz2" -mtime +30 -delete
    find "${backup_dir}" -name "immagini-*.tar.bz2" -mtime +30 -delete
    ok "Backup completato"
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
  build-frontend-pdf        Rigenera solo i pdf dei canti, in-place nella release corrente
  build-canzonieri          Rigenera i canzonieri collettivi, in-place (uso da cron settimanale)
  drush <args>      Esegui comando drush
  migrate [flags]   Importa tutte le migrazioni (ordine di dipendenza)
  allinea-prod      [solo stage] Allinea DB e file da prod (backup.sql + rsync), no conferma (uso da cron)
  backup            Backup completo: dump DB (schema vuoto per cache*/search_api_db_*) + dump immagini
                      entrambi in bz2 in backup/ildeposito/, retention 30 giorni (uso da cron)
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
    build-canzonieri)        shift; cmd_build_frontend canzonieri ;;
    drush)           shift; cmd_drush "$@" ;;
    migrate)         shift; cmd_migrate "$@" ;;
    allinea-prod)    cmd_allinea_prod ;;
    backup)          cmd_backup ;;
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
