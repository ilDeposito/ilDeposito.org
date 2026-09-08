#!/usr/bin/env bash
set -euo pipefail

REPOSITORY='ilDeposito/ilDeposito.org'
STAGE_WORKFLOW='stage.yml'
PROD_WORKFLOW='prod.yml'

die() { printf 'Errore: %s\n' "$*" >&2; exit 1; }
info() { printf '▸ %s\n' "$*"; }

require_command() {
  command -v "$1" >/dev/null 2>&1 || die "Comando richiesto non disponibile: $1"
}

ensure_main_is_ready() {
  [[ "$(git branch --show-current)" == 'main' ]] || die 'Passa prima al branch main.'
  [[ -z "$(git status --porcelain)" ]] || die 'La working tree contiene modifiche non committate.'
  git fetch origin main --tags
  local_head="$(git rev-parse HEAD)"
  remote_head="$(git rev-parse origin/main)"
  [[ "$local_head" == "$remote_head" ]] || die 'Il checkout locale non coincide con origin/main: aggiorna main prima di continuare.'
  printf '%s\n' "$local_head"
}

latest_run_id() {
  local workflow="$1" event="$2" branch="$3" sha="$4"
  local run_id=''
  for _ in {1..20}; do
    run_id="$(gh run list --repo "$REPOSITORY" --workflow "$workflow" --event "$event" --branch "$branch" --limit 20 --json databaseId,headSha --jq ".[] | select(.headSha == \"$sha\") | .databaseId" | head -n1)"
    [[ -n "$run_id" ]] && { printf '%s\n' "$run_id"; return; }
    sleep 2
  done
  return 1
}

watch_run() {
  local run_id="$1"
  info "Run: https://github.com/${REPOSITORY}/actions/runs/${run_id}"
  if gh run watch "$run_id" --repo "$REPOSITORY" --exit-status; then
    info 'Operazione completata con successo.'
    printf '\nLog della run:\n'
    gh run view "$run_id" --repo "$REPOSITORY" --log
  else
    local status=$?
    printf '\nLog degli step falliti:\n' >&2
    gh run view "$run_id" --repo "$REPOSITORY" --log-failed >&2 || true
    return "$status"
  fi
}

deploy_stage() {
  local sha
  sha="$(ensure_main_is_ready)"
  info "Avvio deploy stage per ${sha:0:7}..."
  gh workflow run "$STAGE_WORKFLOW" --repo "$REPOSITORY" --ref main -f operation=deploy
  local run_id
  run_id="$(latest_run_id "$STAGE_WORKFLOW" workflow_dispatch main "$sha")" \
    || die 'La run stage non è comparsa entro 40 secondi.'
  watch_run "$run_id"
}

last_successful_stage_run() {
  local sha="$1"
  gh run list --repo "$REPOSITORY" --workflow "$STAGE_WORKFLOW" --status success --limit 50 --json databaseId,headSha,event --jq ".[] | select(.headSha == \"$sha\" and .event == \"workflow_dispatch\") | .databaseId" | head -n1
}

release() {
  local sha
  sha="$(ensure_main_is_ready)"

  printf 'Ultime tre release:\n'
  gh release list --repo "$REPOSITORY" --limit 3 || true

  local stage_run
  stage_run="$(last_successful_stage_run "$sha")"
  [[ -n "$stage_run" ]] || die "Nessun deploy stage riuscito trovato per ${sha:0:7}. Esegui prima: ./deploy.sh deploy-stage"
  info "Stage verificato: run ${stage_run}."

  local version
  read -r -p 'Nuova versione (es. v2.5.0): ' version
  [[ "$version" =~ ^v[0-9]+(\.[0-9]+)*(-[0-9A-Za-z][0-9A-Za-z.-]*)?$ ]] || die 'Versione non valida.'
  ! git rev-parse --verify --quiet "refs/tags/${version}" >/dev/null || die "Il tag ${version} esiste già localmente."
  ! git ls-remote --exit-code --tags origin "refs/tags/${version}" >/dev/null 2>&1 || die "Il tag ${version} esiste già su origin."

  local previous_tag notes_file editor
  previous_tag="$(gh release list --repo "$REPOSITORY" --limit 1 --json tagName --jq '.[0].tagName')"
  notes_file="$(mktemp)"
  trap 'rm -f "${notes_file:-}"' EXIT
  {
    printf '# %s\n\n## Modifiche\n\n' "$version"
    if [[ -n "$previous_tag" ]]; then
      git log "${previous_tag}..${sha}" --pretty=format:'- %s (%h)%n'
    else
      git log "$sha" --pretty=format:'- %s (%h)%n'
    fi
  } > "$notes_file"
  grep -q '^- ' "$notes_file" || die 'Non ci sono commit nuovi rispetto all’ultima release.'

  editor="${EDITOR:-vi}"
  command -v "$editor" >/dev/null 2>&1 || die "Editor non disponibile: ${editor}"
  "$editor" "$notes_file"

  printf '\nVerrà pubblicata la release %s sul commit %s:\n\n' "$version" "${sha:0:7}"
  cat "$notes_file"
  printf '\n'
  local confirmation
  read -r -p 'Confermi la pubblicazione? [y/N] ' confirmation
  [[ "$confirmation" =~ ^[yY]$ ]] || { info 'Release annullata.'; return; }

  [[ "$(ensure_main_is_ready)" == "$sha" ]] || die 'main è cambiato durante la preparazione della release.'
  [[ -n "$(last_successful_stage_run "$sha")" ]] || die 'Manca un deploy stage riuscito per il commit corrente.'
  git tag -a "$version" "$sha" -m "Release ${version}"
  git push origin "refs/tags/${version}"
  gh release create "$version" --repo "$REPOSITORY" --verify-tag --title "$version" --notes-file "$notes_file"

  local run_id
  run_id="$(latest_run_id "$PROD_WORKFLOW" push "$version" "$sha")" \
    || die 'Tag e release creati, ma la run produzione non è comparsa entro 40 secondi.'
  watch_run "$run_id"
}

usage() {
  cat <<'EOF'
Uso: ./deploy.sh <comando>

Comandi:
  deploy-stage  Avvia e segue il deploy manuale di main su stage.
  release       Crea interattivamente tag e GitHub Release dopo il deploy stage.
EOF
}

require_command git
require_command gh
git rev-parse --show-toplevel >/dev/null 2>&1 || die 'Esegui il comando dentro il repository.'

case "${1:-}" in
  deploy-stage) deploy_stage ;;
  release) release ;;
  -h|--help|help|'') usage ;;
  *) die "Comando sconosciuto: $1" ;;
esac
