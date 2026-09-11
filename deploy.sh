#!/usr/bin/env bash
set -euo pipefail

REPOSITORY='ilDeposito/ilDeposito.org'
STAGE_WORKFLOW='stage.yml'
PROD_WORKFLOW='prod.yml'
TIMER_REFRESH_SECONDS=0.5
GITHUB_STATUS_POLL_TICKS=20
RED=$'\033[0;31m'
GREEN=$'\033[0;32m'
YELLOW=$'\033[0;33m'
CYAN=$'\033[0;36m'
DIM=$'\033[0;2m'
RESET=$'\033[0m'

die() { printf 'Errore: %s\n' "$*" >&2; exit 1; }
info() { printf '%s▸%s %s\n' "$CYAN" "$RESET" "$*"; }
ok() { printf '%s✓%s %s\n' "$GREEN" "$RESET" "$*"; }
failure() { printf '%s✗%s %s\n' "$RED" "$RESET" "$*" >&2; }

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

progress_step_status() {
  local snapshot="$1" expected_name="$2" kind name status conclusion
  while IFS=$'\t' read -r kind name status conclusion; do
    [[ "$kind" == STEP && "$name" == "$expected_name" ]] && {
      printf '%s\t%s\n' "$status" "$conclusion"
      return
    }
  done <<< "$snapshot"
  printf 'queued\t\n'
}

print_progress_step() {
  local name="$1" status="$2" conclusion="$3"
  case "$status" in
    queued|pending|waiting) printf '  %s○%s %s %s— da eseguire%s\n' "$RED" "$RESET" "$name" "$DIM" "$RESET" ;;
    in_progress) printf '  %s◐%s %s %s— in corso%s\n' "$YELLOW" "$RESET" "$name" "$DIM" "$RESET" ;;
    completed)
      if [[ "$conclusion" == success ]]; then
        printf '  %s✓%s %s %s— completato%s\n' "$GREEN" "$RESET" "$name" "$DIM" "$RESET"
      elif [[ "$conclusion" == skipped ]]; then
        printf '  %s–%s %s %s— non eseguito%s\n' "$DIM" "$RESET" "$name" "$DIM" "$RESET"
      else
        printf '  %s✗%s %s %s— %s%s\n' "$RED" "$RESET" "$name" "$DIM" "${conclusion:-errore}" "$RESET"
      fi
      ;;
  esac
}

format_duration() {
  local seconds="$1"
  printf '%02d:%02d' "$((seconds / 60))" "$((seconds % 60))"
}

format_duration_words() {
  local seconds="$1" minutes remaining_seconds
  minutes="$((seconds / 60))"
  remaining_seconds="$((seconds % 60))"
  local minute_word='minuti' second_word='secondi'
  [[ "$minutes" == 1 ]] && minute_word='minuto'
  [[ "$remaining_seconds" == 1 ]] && second_word='secondo'
  printf '%d %s e %d %s' "$minutes" "$minute_word" "$remaining_seconds" "$second_word"
}

render_progress() {
  local snapshot="$1" expected_steps="$2" interactive="$3" elapsed="$4" name status conclusion
  local -a steps
  IFS='|' read -r -a steps <<< "$expected_steps"
  if (( interactive && PROGRESS_LINES > 0 )); then
    printf '\033[%dA\033[J' "$PROGRESS_LINES"
  fi
  printf '  %sTempo trascorso: %s%s\n\n' "$DIM" "$elapsed" "$RESET"
  for name in "${steps[@]}"; do
    IFS=$'\t' read -r status conclusion < <(progress_step_status "$snapshot" "$name")
    print_progress_step "$name" "$status" "$conclusion"
  done
  PROGRESS_LINES="$(( ${#steps[@]} + 2 ))"
}

watch_run() {
  local run_id="$1" expected_steps="$2" deployment_label="$3" snapshot='' previous_snapshot='' state_line='' status='' conclusion='' interactive=0 started_at elapsed elapsed_seconds poll_ticks=0
  [[ -t 1 ]] && interactive=1
  started_at="$(date +%s)"
  PROGRESS_LINES=0
  render_progress '' "$expected_steps" "$interactive" '00:00'
  while :; do
    if (( poll_ticks == 0 )); then
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
      poll_ticks="$GITHUB_STATUS_POLL_TICKS"
    fi
    elapsed_seconds="$(( $(date +%s) - started_at ))"
    elapsed="$(format_duration "$elapsed_seconds")"
    if (( interactive )) || [[ "$snapshot" != "$previous_snapshot" ]]; then
      render_progress "$snapshot" "$expected_steps" "$interactive" "$elapsed"
      previous_snapshot="$snapshot"
    fi

    state_line="${snapshot%%$'\n'*}"
    IFS=$'\t' read -r _ status conclusion <<< "$state_line"
    [[ "$status" == completed ]] || {
      poll_ticks="$((poll_ticks - 1))"
      sleep "$TIMER_REFRESH_SECONDS"
      continue
    }
    [[ "$conclusion" == success ]] && { ok "${deployment_label} eseguito in $(format_duration_words "$elapsed_seconds")."; return 0; }
    failure "${deployment_label} fallito dopo $(format_duration_words "$elapsed_seconds"): ${conclusion:-sconosciuto}."
    print_failed_run_logs "$run_id"
    return 1
  done
}

print_failed_run_logs() {
  local run_id="$1" run_url=''
  printf '\n%sLog dei passaggi falliti (run %s):%s\n' "$YELLOW" "$run_id" "$RESET" >&2
  if ! gh run view "$run_id" --repo "$REPOSITORY" --log-failed >&2; then
    failure 'Impossibile recuperare i log del fallimento dalla GitHub CLI.'
  fi
  run_url="$(gh run view "$run_id" --repo "$REPOSITORY" --json url --jq .url 2>/dev/null || true)"
  [[ -n "$run_url" ]] && printf 'Run GitHub: %s\n' "$run_url" >&2
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
  watch_run "$run_id" 'Prepara deploy e dipendenze|Aggiorna Drupal e indice di ricerca|Genera sito e PDF' 'Deploy in Stage' || die 'Il deploy stage non è riuscito.'
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

prod() {
  local sha
  sha="$(ensure_main_is_ready)"

  local stage_run
  stage_run="$(last_successful_stage_run "$sha")"
  [[ -n "$stage_run" ]] || die "Non puoi avviare il deploy in produzione: manca un deploy stage riuscito per ${sha:0:7}. Esegui prima: ./deploy.sh stage"
  info "Stage verificato: run ${stage_run}."

  local previous_tag
  previous_tag="$(gh release list --repo "$REPOSITORY" --limit 1 --json tagName --jq '.[0].tagName')"

  local version
  read -e -r -p "Nuova versione (ultimo tag rilasciato: ${previous_tag:-nessuno}): " version
  [[ "$version" =~ ^v[0-9]+(\.[0-9]+)*(-[0-9A-Za-z][0-9A-Za-z.-]*)?$ ]] || die 'Versione non valida.'
  ! git rev-parse --verify --quiet "refs/tags/${version}" >/dev/null || die "Il tag ${version} esiste già localmente."
  ! git ls-remote --exit-code --tags origin "refs/tags/${version}" >/dev/null 2>&1 || die "Il tag ${version} esiste già su origin."

  local notes_file
  notes_file="$(mktemp)"
  trap 'rm -f "${notes_file:-}"' EXIT
  {
    printf '# %s\n\n## Modifiche\n\n' "$version"
    if [[ -n "$previous_tag" ]]; then
      git log "${previous_tag}..${sha}" --pretty=tformat:'- %s (%h)'
    else
      git log "$sha" --pretty=tformat:'- %s (%h)'
    fi
  } > "$notes_file"
  grep -q '^- ' "$notes_file" || die 'Non ci sono commit nuovi rispetto all’ultima release.'

  require_command nano
  printf '\nSi apre nano: salva con Ctrl+O, premi Invio per confermare il nome del file, poi esci con Ctrl+X.\n\n'
  nano "$notes_file"

  printf '\nVerrà pubblicata la release %s sul commit %s:\n\n' "$version" "${sha:0:7}"
  cat "$notes_file"
  printf '\n'
  local confirmation
  read -e -r -p 'Confermi la pubblicazione? [y/N] ' confirmation
  [[ "$confirmation" =~ ^[yY]$ ]] || { info 'Release annullata.'; return; }

  [[ "$(ensure_main_is_ready)" == "$sha" ]] || die 'main è cambiato durante la preparazione della release.'
  [[ -n "$(last_successful_stage_run "$sha")" ]] || die 'Manca un deploy stage riuscito per il commit corrente.'
  local known_runs run_id
  known_runs="$(known_run_ids "$PROD_WORKFLOW" push "$version")"
  git tag -a "$version" "$sha" -m "Release ${version}"
  git push origin "refs/tags/${version}"
  gh release create "$version" --repo "$REPOSITORY" --verify-tag --title "$version" --notes-file "$notes_file"
  info "Lancio il workflow del repository per il rilascio del tag ${version} in produzione..."

  run_id="$(latest_new_run_id "$PROD_WORKFLOW" push "$version" "$sha" "$known_runs")" \
    || die 'Tag e release creati, ma la run produzione non è comparsa entro 40 secondi.'
  watch_run "$run_id" 'Verifica deploy stage|Prepara deploy e dipendenze|Aggiorna Drupal e indice di ricerca|Genera sito, PDF e redirect' "Deploy in Produzione della versione ${version}" || die 'Il deploy in produzione non è riuscito.'
}

usage() {
  cat <<'EOF'
Uso: ./deploy.sh <comando>

Comandi:
  stage  Avvia e segue il deploy manuale di main su stage.
  prod   Crea interattivamente tag e GitHub Release dopo il deploy stage.
EOF
}

require_command git
require_command gh
git rev-parse --show-toplevel >/dev/null 2>&1 || die 'Esegui il comando dentro il repository.'

case "${1:-}" in
  stage) deploy_stage ;;
  prod) prod ;;
  -h|--help|help|'') usage ;;
  *) die "Comando sconosciuto: $1" ;;
esac
