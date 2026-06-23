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

cmd_up() {
    info "Avvio ambiente locale..."
    local logfile
    logfile=$(mktemp)
    if ddev start >"$logfile" 2>&1; then
        ok "Ambiente avviato"
        info "Drupal:       https://ildeposito11.ddev.site"
        info "Frontend dev: https://ildeposito11.ddev.site:4322"
    else
        error "Avvio fallito"
        cat "$logfile"
        rm -f "$logfile"
        exit 1
    fi
    rm -f "$logfile"
}

cmd_stop() {
    info "Arresto ambiente locale..."
    ddev stop
    ok "Ambiente arrestato"
}

cmd_restart() {
    info "Riavvio ambiente locale..."
    ddev restart
    ok "Ambiente riavviato"
}

cmd_build() {
    info "Conto i contenuti pubblicati..."
    local total
    total=$(ddev drush sqlq "SELECT COUNT(*) FROM node_field_data WHERE status = 1" 2>/dev/null | tr -d '[:space:]')

    if [[ -z "$total" || "$total" -eq 0 ]]; then
        error "Impossibile contare i nodi. DDEV è attivo?"
        exit 1
    fi

    info "Nodi pubblicati: ${total}"
    info "Avvio build Astro..."

    if [[ -d "${PROJECT_ROOT}/frontend/dist" ]]; then
        info "Pulizia dist/ precedente..."
        rm -rf "${PROJECT_ROOT}/frontend/dist" 2>/dev/null || true
        if [[ -d "${PROJECT_ROOT}/frontend/dist" ]]; then
            error "Impossibile eliminare dist/ — controlla se è in uso"
            exit 1
        fi
    fi

    local logfile
    logfile=$(mktemp)

    set +e
    (cd "${PROJECT_ROOT}/frontend" && npm run build) >"$logfile" 2>&1 &
    local pid=$!

    local count=0 pct=0
    while kill -0 "$pid" 2>/dev/null; do
        count=$(find "${PROJECT_ROOT}/frontend/dist" -name "*.html" 2>/dev/null | wc -l | tr -d ' ')
        if [[ "$count" -gt 0 ]]; then
            pct=$((count * 100 / total))
            [[ "$pct" -gt 100 ]] && pct=100
            printf "\r  ${CYAN}Building...${NC} %d/%d nodi (%d%%)" "$count" "$total" "$pct"
        fi
        sleep 0.5
    done

    wait "$pid"
    local exit_code=$?
    set -e

    count=$(find "${PROJECT_ROOT}/frontend/dist" -name "*.html" 2>/dev/null | wc -l | tr -d ' ')

    if [[ $exit_code -eq 0 ]]; then
        printf "\r  ${GREEN}✓${NC} Build completata: %d pagine generate (da %d nodi)    \n" "$count" "$total"
        docker restart ddev-ildeposito11-astro-static >/dev/null 2>&1 && \
            ok "Container frontend riavviato → https://frontend.ildeposito11.ddev.site"
    else
        printf "\n"
        error "Build fallita (exit code: ${exit_code})"
        echo ""
        cat "$logfile"
        rm -f "$logfile"
        exit "$exit_code"
    fi

    rm -f "$logfile"
}

cmd_allinea() {
    warn "Comando 'allinea' non ancora implementato"
    info "In futuro allineerà il database da produzione a locale"
}

usage() {
    cat <<EOF
${BOLD}Uso:${NC} ./local.sh <comando>

${BOLD}Comandi:${NC}
  up        Avvia l'ambiente locale (DDEV)
  stop      Arresta l'ambiente locale
  restart   Riavvia l'ambiente locale
  build     Build statica del frontend Astro (con progresso)
  allinea   Allinea il DB da produzione (non ancora implementato)
EOF
}

case "${1:-}" in
    up)                cmd_up ;;
    stop)              cmd_stop ;;
    restart)           cmd_restart ;;
    build)             cmd_build ;;
    allinea)           cmd_allinea ;;
    -h | --help | help) usage ;;
    "")                usage; exit 1 ;;
    *)                 error "Comando sconosciuto: $1"; usage; exit 1 ;;
esac
