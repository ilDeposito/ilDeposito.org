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
  git fetch origin main --tags --quiet
  local_head="$(git rev-parse HEAD)"
  remote_head="$(git rev-parse origin/main)"
  [[ "$local_head" == "$remote_head" ]] || die 'Il checkout locale non coincide con origin/main: aggiorna main prima di continuare.'
  printf '%s\n' "$local_head"
}

known_run_ids() {
  local workflow="$1" event="$2" branch="$3"
  gh run list --repo "$REPOSITORY" --workflow "$workflow" --event "$event" --branch "$branch" \
    --limit 100 --json databaseId --jq '.[].databaseId'
}

is_known_run() {
  local run_id="$1" known_runs="$2"
  [[ $'\n'"$known_runs"$'\n' == *$'\n'"$run_id"$'\n'* ]]
}

latest_new_run_id() {
  local workflow="$1" event="$2" branch="$3" sha="$4" known_runs="$5"
  local run_id=''
  for _ in {1..20}; do
    while IFS= read -r run_id; do
      [[ -n "$run_id" ]] && ! is_known_run "$run_id" "$known_runs" && {
        printf '%s\n' "$run_id"
        return
      }
    done < <(gh run list --repo "$REPOSITORY" --workflow "$workflow" --event "$event" --branch "$branch" \
      --limit 100 --json databaseId,headSha --jq ".[] | select(.headSha == \"$sha\") | .databaseId")
    sleep 2
  done
  return 1
}

snapshot_contains_line() {
  local snapshot="$1" line="$2"
  [[ $'\n'"$snapshot"$'\n' == *$'\n'"$line"$'\n'* ]]
}

print_run_snapshot() {
  local snapshot="$1" previous_snapshot="$2" line='' kind name status conclusion
  while IFS=$'\t' read -r kind name status conclusion; do
    line="$kind"$'\t'"$name"$'\t'"$status"$'\t'"$conclusion"
    snapshot_contains_line "$previous_snapshot" "$line" && continue
    case "$kind" in
      RUN) ;;
      STEP)
        case "$status" in
          queued|pending|waiting) printf '  • %s — in attesa\n' "$name" ;;
          in_progress) printf '  • %s — in corso\n' "$name" ;;
          completed)
            [[ "$conclusion" == success ]] && printf '  • %s — completato\n' "$name" \
              || printf '  • %s — %s\n' "$name" "${conclusion:-concluso}"
            ;;
        esac
        ;;
    esac
  done <<< "$snapshot"
}

watch_run() {
  local run_id="$1" snapshot='' previous_snapshot='' state_line='' status='' conclusion=''
  while :; do
    snapshot="$(gh run view "$run_id" --repo "$REPOSITORY" --json status,conclusion,jobs --jq '
      [
        "RUN\t" + .status + "\t" + (.conclusion // ""),
        (.jobs[]? as $job |
          if ($job.steps | type) == "array" then
            $job.steps[] | "STEP\t\(.name)\t\(.status)\t\(.conclusion // "")"
          else
            "STEP\t\($job.name)\t\($job.status)\t\($job.conclusion // "")"
          end)
      ] | .[]')"
    if [[ "$snapshot" != "$previous_snapshot" ]]; then
      print_run_snapshot "$snapshot" "$previous_snapshot"
      previous_snapshot="$snapshot"
    fi

    state_line="${snapshot%%$'\n'*}"
    IFS=$'\t' read -r _ status conclusion <<< "$state_line"
    [[ "$status" == completed ]] || { sleep 3; continue; }
    [[ "$conclusion" == success ]] && { info 'Workflow completato.'; return 0; }
    printf 'Errore: workflow fallito: %s.\n' "${conclusion:-sconosciuto}" >&2
    return 1
  done
}

deploy_stage() {
  local sha known_runs
  sha="$(ensure_main_is_ready)"
  known_runs="$(known_run_ids "$STAGE_WORKFLOW" workflow_dispatch main)"
  info 'Lancio il workflow del repository main su stage...'
  gh workflow run "$STAGE_WORKFLOW" --repo "$REPOSITORY" --ref main -f operation=deploy >/dev/null
  local run_id
  run_id="$(latest_new_run_id "$STAGE_WORKFLOW" workflow_dispatch main "$sha" "$known_runs")" \
    || die 'La run stage non è comparsa entro 40 secondi.'
  watch_run "$run_id" || die 'Il deploy stage non è riuscito.'
}

last_successful_stage_run() {
  local sha="$1" run_id='' status='' conclusion='' row
  row="$(gh run list --repo "$REPOSITORY" --workflow "$STAGE_WORKFLOW" --event workflow_dispatch --limit 100 \
    --json databaseId,headSha,status,conclusion --jq ".[] | select(.headSha == \"$sha\") | [.databaseId, .status, (.conclusion // \"\")] | @tsv" | head -n1)"
  [[ -n "$row" ]] || return 0
  IFS=$'\t' read -r run_id status conclusion <<< "$row"
  [[ "$status" == completed && "$conclusion" == success ]] || return 0
  printf '%s\n' "$run_id"
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
  local known_runs run_id
  known_runs="$(known_run_ids "$PROD_WORKFLOW" push "$version")"
  git tag -a "$version" "$sha" -m "Release ${version}"
  git push origin "refs/tags/${version}"
  gh release create "$version" --repo "$REPOSITORY" --verify-tag --title "$version" --notes-file "$notes_file"

  run_id="$(latest_new_run_id "$PROD_WORKFLOW" push "$version" "$sha" "$known_runs")" \
    || die 'Tag e release creati, ma la run produzione non è comparsa entro 40 secondi.'
  watch_run "$run_id" || die 'Il deploy in produzione non è riuscito.'
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
