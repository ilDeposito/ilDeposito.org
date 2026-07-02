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
    info "Pulizia cache Vite..."
    rm -rf "${PROJECT_ROOT}/frontend/node_modules/.vite"

    info "Avvio build Astro..."
    local start_time=$SECONDS

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
        local elapsed=$(( SECONDS - start_time ))
        local mins=$(( elapsed / 60 ))
        local secs=$(( elapsed % 60 ))
        printf "\r  ${GREEN}✓${NC} Build completata: %d pagine generate (da %d nodi) in %dm %ds    \n" "$count" "$total" "$mins" "$secs"
        # Marker scritto solo a build completa: il container aspetta questo file
        # prima di avviare Node, evitando race condition sui chunk SSR.
        touch "${PROJECT_ROOT}/frontend/dist/.build-complete"
        docker restart ddev-ildeposito11-astro-static >/dev/null 2>&1
        docker restart ddev-ildeposito11-astro-node   >/dev/null 2>&1
        ok "Container frontend riavviati → https://frontend.ildeposito11.ddev.site"
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

npm_dryrun_conflicts() {
    local frontend_dir="${PROJECT_ROOT}/frontend"
    local dryrun_output
    dryrun_output=$(cd "$frontend_dir" && npm update --dry-run 2>&1) || true

    local conflicts
    conflicts=$(echo "$dryrun_output" | python3 -c "
import sys, re

lines = sys.stdin.read().splitlines()
changes = {}
peer_warnings = []

for line in lines:
    stripped = line.strip()
    m = re.match(r'^change\s+(\S+)\s+(\S+)\s+=>\s+(\S+)$', stripped)
    if m:
        name, old_ver, new_ver = m.groups()
        changes[name] = (old_ver, new_ver)
    if 'ERESOLVE' in stripped or 'peer dep' in stripped.lower():
        peer_warnings.append(stripped)

critical = ['vite', 'rollup', 'rolldown', 'esbuild', 'lightningcss']
conflicts = []

for name in critical:
    if name in changes:
        old_v, new_v = changes[name]
        old_major = old_v.split('.')[0]
        new_major = new_v.split('.')[0]
        if old_major != new_major:
            conflicts.append(f'{name}: {old_v} -> {new_v} (major {old_major} -> {new_major})')

for w in peer_warnings:
    conflicts.append(f'npm: {w}')

for c in conflicts:
    print(c)
" 2>/dev/null)

    echo "$conflicts"
}

cmd_outdated() {
    local drupal_count=0 npm_count=0 drupal_security=0 npm_security=0

    printf "\n${BOLD}═══ Backend (Drupal/Composer) ═══${NC}\n\n"
    info "Controllo pacchetti drupal/* outdated..."

    local composer_output
    composer_output=$(ddev composer outdated 'drupal/*' --direct --format=json 2>/dev/null) || true

    if [[ -n "$composer_output" ]]; then
        drupal_count=$(echo "$composer_output" | python3 -c "import sys,json; data=json.load(sys.stdin); print(len(data.get('installed',[])))" 2>/dev/null || echo "0")

        if [[ "$drupal_count" -gt 0 ]]; then
            printf "  ${YELLOW}%-40s %-12s %-12s %s${NC}\n" "PACCHETTO" "ATTUALE" "DISPONIBILE" "TIPO"
            echo "$composer_output" | python3 -c "
import sys, json
data = json.load(sys.stdin)
for pkg in data.get('installed', []):
    name = pkg.get('name','')
    current = pkg.get('version','')
    latest = pkg.get('latest','')
    abandoned = ' ⚠ ABBANDONATO' if pkg.get('abandoned') else ''
    # semver major = major upgrade
    cur_parts = current.lstrip('v').split('.')
    lat_parts = latest.lstrip('v').split('.')
    if cur_parts[0] != lat_parts[0]:
        tipo = 'MAJOR'
    elif len(cur_parts) > 1 and len(lat_parts) > 1 and cur_parts[1] != lat_parts[1]:
        tipo = 'MINOR'
    else:
        tipo = 'PATCH'
    print(f'  {name:<40} {current:<12} {latest:<12} {tipo}{abandoned}')
" 2>/dev/null
        else
            ok "Tutti i pacchetti drupal/* sono aggiornati"
        fi
    else
        ok "Tutti i pacchetti drupal/* sono aggiornati"
    fi

    # Drupal security advisories
    info "Controllo vulnerabilità Composer..."
    local composer_audit
    composer_audit=$(ddev composer audit --format=json 2>/dev/null) || true
    if [[ -n "$composer_audit" ]]; then
        drupal_security=$(echo "$composer_audit" | python3 -c "
import sys, json
data = json.load(sys.stdin)
advisories = data.get('advisories', {})
count = sum(len(v) for v in advisories.values())
print(count)
" 2>/dev/null || echo "0")
        if [[ "$drupal_security" -gt 0 ]]; then
            warn "Trovate ${drupal_security} vulnerabilità note!"
            echo "$composer_audit" | python3 -c "
import sys, json
data = json.load(sys.stdin)
for pkg, advs in data.get('advisories', {}).items():
    for adv in advs:
        title = adv.get('title', 'N/A')
        print(f'  \033[0;31m✗\033[0m {pkg}: {title}')
" 2>/dev/null
        else
            ok "Nessuna vulnerabilità nota (composer audit)"
        fi
    else
        ok "Nessuna vulnerabilità nota (composer audit)"
    fi

    printf "\n${BOLD}═══ Frontend (Astro/npm) ═══${NC}\n\n"
    info "Controllo pacchetti npm outdated..."

    local npm_output
    npm_output=$(cd "${PROJECT_ROOT}/frontend" && npm outdated --json 2>/dev/null) || true

    if [[ -n "$npm_output" && "$npm_output" != "{}" ]]; then
        npm_count=$(echo "$npm_output" | python3 -c "import sys,json; print(len(json.load(sys.stdin)))" 2>/dev/null || echo "0")

        if [[ "$npm_count" -gt 0 ]]; then
            printf "  ${YELLOW}%-35s %-12s %-12s %-12s %s${NC}\n" "PACCHETTO" "ATTUALE" "WANTED" "LATEST" "TIPO"
            echo "$npm_output" | python3 -c "
import sys, json
data = json.load(sys.stdin)
for name, info in sorted(data.items()):
    current = info.get('current', '?')
    wanted = info.get('wanted', '?')
    latest = info.get('latest', '?')
    cur_parts = current.split('.')
    lat_parts = latest.split('.')
    if cur_parts[0] != lat_parts[0]:
        tipo = 'MAJOR'
    elif len(cur_parts) > 1 and len(lat_parts) > 1 and cur_parts[1] != lat_parts[1]:
        tipo = 'MINOR'
    else:
        tipo = 'PATCH'
    print(f'  {name:<35} {current:<12} {wanted:<12} {latest:<12} {tipo}')
" 2>/dev/null
        fi
    else
        ok "Tutti i pacchetti npm sono aggiornati"
    fi

    # npm audit
    info "Controllo vulnerabilità npm..."
    local npm_audit
    npm_audit=$(cd "${PROJECT_ROOT}/frontend" && npm audit --json 2>/dev/null) || true
    if [[ -n "$npm_audit" ]]; then
        npm_security=$(echo "$npm_audit" | python3 -c "
import sys, json
data = json.load(sys.stdin)
meta = data.get('metadata', {}).get('vulnerabilities', {})
total = meta.get('low',0) + meta.get('moderate',0) + meta.get('high',0) + meta.get('critical',0)
print(total)
" 2>/dev/null || echo "0")
        if [[ "$npm_security" -gt 0 ]]; then
            warn "Trovate ${npm_security} vulnerabilità!"
            echo "$npm_audit" | python3 -c "
import sys, json
data = json.load(sys.stdin)
meta = data.get('metadata', {}).get('vulnerabilities', {})
for sev in ['critical', 'high', 'moderate', 'low']:
    count = meta.get(sev, 0)
    if count > 0:
        icon = '\033[0;31m✗\033[0m' if sev in ('critical','high') else '\033[0;33m⚠\033[0m'
        print(f'  {icon} {count} {sev}')
" 2>/dev/null
        else
            ok "Nessuna vulnerabilità nota (npm audit)"
        fi
    else
        ok "Nessuna vulnerabilità nota (npm audit)"
    fi

    # Analisi compatibilità
    local npm_conflicts=0
    if [[ "$npm_count" -gt 0 ]]; then
        info "Simulazione aggiornamento (dry-run)..."
        local conflicts
        conflicts=$(npm_dryrun_conflicts)
        if [[ -n "$conflicts" ]]; then
            npm_conflicts=$(echo "$conflicts" | wc -l | tr -d ' ')
            echo ""
            printf "  ${RED}${BOLD}⚠ Incompatibilità rilevate:${NC}\n"
            while IFS= read -r line; do
                printf "    ${RED}✗${NC} %s\n" "$line"
            done <<< "$conflicts"
            echo ""
            warn "upgrade frontend potrebbe rompere la build — aggiornare i pacchetti singolarmente"
        else
            ok "Nessuna incompatibilità rilevata nella simulazione"
        fi
    fi

    # Resoconto finale
    printf "\n${BOLD}═══ Resoconto ═══${NC}\n\n"
    printf "  Drupal (drupal/*):  ${BOLD}%d${NC} pacchetti da aggiornare\n" "$drupal_count"
    printf "  Frontend (npm):     ${BOLD}%d${NC} pacchetti da aggiornare\n" "$npm_count"
    if [[ "$npm_conflicts" -gt 0 ]]; then
        printf "  ${RED}Compatibilità:      %d conflitti — upgrade manuale consigliato${NC}\n" "$npm_conflicts"
    fi
    if [[ "$drupal_security" -gt 0 || "$npm_security" -gt 0 ]]; then
        printf "  ${RED}Sicurezza:          %d composer + %d npm vulnerabilità${NC}\n" "$drupal_security" "$npm_security"
    else
        printf "  ${GREEN}Sicurezza:          Nessuna vulnerabilità nota${NC}\n"
    fi
    echo ""
}

cmd_upgrade() {
    local target="${1:-}"
    if [[ -z "$target" ]]; then
        error "Specificare un target: backend o frontend"
        info "Uso: ./local.sh upgrade <backend|frontend>"
        exit 1
    fi

    case "$target" in
        backend)
            cmd_upgrade_backend
            ;;
        frontend)
            cmd_upgrade_frontend
            ;;
        *)
            error "Target sconosciuto: $target"
            info "Uso: ./local.sh upgrade <backend|frontend>"
            exit 1
            ;;
    esac
}

cmd_upgrade_backend() {
    info "Aggiornamento pacchetti drupal/*..."

    ddev composer update --with-dependencies 'drupal/*'
    local exit_code=$?

    if [[ $exit_code -eq 0 ]]; then
        ok "Pacchetti drupal/* aggiornati"
        echo ""
        info "Eseguo database update e cache rebuild..."
        ddev drush updatedb -y
        ddev drush cr
        ok "Database aggiornato e cache pulita"
        echo ""
        warn "Ricorda di verificare il sito e committare composer.json + composer.lock"
    else
        error "Aggiornamento fallito (exit code: ${exit_code})"
        exit "$exit_code"
    fi
}

cmd_upgrade_frontend() {
    local frontend_dir="${PROJECT_ROOT}/frontend"

    # Pre-check: simulazione dry-run per incompatibilità
    info "Simulazione aggiornamento (dry-run)..."
    local conflicts
    conflicts=$(npm_dryrun_conflicts)

    if [[ -n "$conflicts" ]]; then
        echo ""
        printf "  ${RED}${BOLD}⚠ Incompatibilità rilevate:${NC}\n"
        while IFS= read -r line; do
            printf "    ${RED}✗${NC} %s\n" "$line"
        done <<< "$conflicts"
        echo ""
        error "Aggiornamento bloccato — major bump su dipendenze di build"
        info "Aggiorna i pacchetti singolarmente per evitare conflitti:"
        info "  cd frontend && npm install <pacchetto>@<versione>"
        info "Usa ./local.sh outdated per la lista completa"
        exit 1
    fi

    ok "Nessuna incompatibilità rilevata"
    echo ""
    info "Aggiornamento pacchetti npm..."

    # Backup lockfile per rollback in caso di build rotta
    cp "${frontend_dir}/package-lock.json" "${frontend_dir}/package-lock.json.bak"

    (cd "$frontend_dir" && npm update)
    local exit_code=$?

    if [[ $exit_code -ne 0 ]]; then
        error "npm update fallito (exit code: ${exit_code})"
        mv "${frontend_dir}/package-lock.json.bak" "${frontend_dir}/package-lock.json"
        exit "$exit_code"
    fi

    ok "Pacchetti npm aggiornati (entro i range di package.json)"
    echo ""
    info "Verifico build..."

    if (cd "$frontend_dir" && npm run build) >/dev/null 2>&1; then
        rm -f "${frontend_dir}/package-lock.json.bak"
        ok "Build completata con successo"
        echo ""
        warn "Ricorda di verificare il sito e committare package.json + package-lock.json"
    else
        warn "Build fallita — rollback del lockfile in corso..."
        mv "${frontend_dir}/package-lock.json.bak" "${frontend_dir}/package-lock.json"
        (cd "$frontend_dir" && npm ci) >/dev/null 2>&1
        ok "Rollback completato, pacchetti ripristinati"
        echo ""
        error "Alcuni aggiornamenti rompono la build"
        info "Aggiorna i pacchetti singolarmente con: cd frontend && npm install <pacchetto>@<versione>"
        exit 1
    fi
}

cmd_linkcheck() {
    local dist="${PROJECT_ROOT}/frontend/dist/client"
    if [[ ! -d "$dist" ]]; then
        error "Build statica non trovata in ${dist}"
        info "Esegui prima: ./local.sh build"
        exit 1
    fi
    info "Verifica link interni sul build statico..."
    node "${PROJECT_ROOT}/frontend/scripts/linkcheck.mjs" "$dist"
}

cmd_allinea() {
    warn "Comando 'allinea' non ancora implementato"
    info "In futuro allineerà il database da produzione a locale"
}

usage() {
    printf "%bUso:%b ./local.sh <comando>\n" "$BOLD" "$NC"
    echo ""
    printf "%bComandi:%b\n" "$BOLD" "$NC"
    printf "  %b%-22s%b %s\n" "$CYAN"   "up"               "$NC" "Avvia l'ambiente locale (DDEV)"
    printf "  %b%-22s%b %s\n" "$CYAN"   "stop"             "$NC" "Arresta l'ambiente locale"
    printf "  %b%-22s%b %s\n" "$CYAN"   "restart"          "$NC" "Riavvia l'ambiente locale"
    printf "  %b%-22s%b %s\n" "$CYAN"   "build"            "$NC" "Build statica del frontend Astro (con progresso)"
    printf "  %b%-22s%b %s\n" "$CYAN"   "linkcheck"        "$NC" "Verifica link interni rotti nel build statico"
    printf "  %b%-22s%b %s\n" "$CYAN"   "outdated"    "$NC" "Verifica aggiornamenti backend e frontend"
    printf "  %b%-22s%b %s\n" "$CYAN"   "upgrade <target>" "$NC" "Aggiorna pacchetti (target: backend | frontend)"
    printf "  %b%-22s%b %s\n" "$YELLOW" "allinea"          "$NC" "Allinea il DB da produzione (non ancora implementato)"
}

cmd_completions() {
    cat <<'COMP'
_local_sh() {
    local commands=(
        'up:Avvia l'\''ambiente locale (DDEV)'
        'stop:Arresta l'\''ambiente locale'
        'restart:Riavvia l'\''ambiente locale'
        'build:Build statica del frontend Astro'
        'linkcheck:Verifica link interni rotti nel build statico'
        'outdated:Verifica aggiornamenti backend e frontend'
        'upgrade:Aggiorna pacchetti (backend | frontend)'
        'allinea:Allinea il DB da produzione'
        'help:Mostra l'\''aiuto'
    )

    if (( CURRENT == 2 )); then
        _describe 'comando' commands
    elif (( CURRENT == 3 )) && [[ "${words[2]}" == "upgrade" ]]; then
        local targets=('backend:Aggiorna drupal/*' 'frontend:Aggiorna pacchetti npm')
        _describe 'target' targets
    fi
}
compdef _local_sh local.sh
compdef _local_sh ./local.sh
COMP
}

case "${1:-}" in
    up)                cmd_up ;;
    stop)              cmd_stop ;;
    restart)           cmd_restart ;;
    build)             cmd_build ;;
    linkcheck)         cmd_linkcheck ;;
    outdated)     cmd_outdated ;;
    upgrade)           cmd_upgrade "${2:-}" ;;
    allinea)           cmd_allinea ;;
    completions)       cmd_completions ;;
    -h | --help | help) usage ;;
    "")                usage; exit 1 ;;
    *)                 error "Comando sconosciuto: $1"; usage; exit 1 ;;
esac
