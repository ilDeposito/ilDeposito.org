#!/usr/bin/env bash
set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"

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

info()  { printf "${CYAN}▸${NC} %s\n" "$*"; }
ok()    { printf "${GREEN}✓${NC} %s\n" "$*"; }
warn()  { printf "${YELLOW}⚠${NC} %s\n" "$*"; }
error() { printf "${RED}✗${NC} %s\n" "$*" >&2; }

cmd_up() {
    info "Avvio ambiente ${ENV} (${PROJECT_NAME})..."
    ${COMPOSE} up -d
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
    info "Build frontend Astro [${ENV}] (zero-downtime)..."

    info "Rebuild immagine astro-builder..."
    ${COMPOSE} build astro-builder

    info "Avvio build..."
    ${COMPOSE} run --rm astro-builder

    ok "Build frontend completata"
    info "Il sito è live su https://${ENV}.ildeposito.org"
}

cmd_drush() {
    ${COMPOSE} exec php drush -r /var/www/html/web "$@"
}

cmd_composer() {
    ${COMPOSE} exec php composer --working-dir=/var/www/html "$@"
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

usage() {
    cat <<EOF
${BOLD}Uso:${NC} ./ildeposito.sh <comando>

${BOLD}Ambiente:${NC} ${ENV} (${PROJECT_NAME})

${BOLD}Comandi:${NC}
  up                Avvia l'ambiente
  down              Rimuovi containers e reti
  stop              Arresta l'ambiente
  restart           Riavvia l'ambiente
  build-frontend    Build Astro + deploy zero-downtime
  drush <args>      Esegui comando drush
  composer <args>   Esegui comando composer
  shell [servizio]  Shell nel container (default: php)
  logs [servizio]   Visualizza i log
  ps                Lista dei container attivi
EOF
}

case "${1:-}" in
    up)              cmd_up ;;
    down)            cmd_down ;;
    stop)            cmd_stop ;;
    restart)         cmd_restart ;;
    build-frontend)  shift; cmd_build_frontend ;;
    drush)           shift; cmd_drush "$@" ;;
    composer)        shift; cmd_composer "$@" ;;
    shell)           shift; cmd_shell "$@" ;;
    logs)            shift; cmd_logs "$@" ;;
    ps)              cmd_ps ;;
    -h|--help|help)  usage ;;
    "")              usage; exit 1 ;;
    *)               error "Comando sconosciuto: $1"; usage; exit 1 ;;
esac
