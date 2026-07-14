#!/bin/sh
set -e

# Modalità: full (default, contenuti + pdf) | content (contenuti, no pdf) |
# pdf (rigenera solo i pdf dei canti nella release "current" già live,
# in-place) | canzonieri (rigenera i canzonieri collettivi, in-place, uso da
# cron settimanale — vedi ildeposito.sh build-canzonieri) | redirect (rigenera
# solo _redirects.conf nella release "current", in-place — vedi
# ildeposito.sh build-redirect).
MODE="${1:-full}"
OUTPUT_DIR="/app/output"

# Timing per fase: chiamata a inizio di ogni fase, stampa la durata di quella
# precedente e il totale — sui log del runner si vede dove va il tempo.
BUILD_T0=$(date +%s)
PHASE_T=$BUILD_T0
phase() {
  now=$(date +%s)
  echo "→ [+$((now - PHASE_T))s, tot $((now - BUILD_T0))s] $1"
  PHASE_T=$now
}

if [ "$MODE" = "pdf" ]; then
  CURRENT_DIR="$OUTPUT_DIR/current"
  if [ ! -f "$CURRENT_DIR/server/entry.mjs" ]; then
    echo "✗ Nessuna release trovata in $CURRENT_DIR. Esegui prima una build contenuti/completa." >&2
    exit 1
  fi
  echo "→ Rigenero i PDF nella release corrente ($CURRENT_DIR) ..."
  PDF_OUT_DIR="$CURRENT_DIR/client/pdf/canti" node scripts/generate-pdfs.mjs
  exit 0
fi

if [ "$MODE" = "canzonieri" ]; then
  CURRENT_DIR="$OUTPUT_DIR/current"
  if [ ! -f "$CURRENT_DIR/server/entry.mjs" ]; then
    echo "✗ Nessuna release trovata in $CURRENT_DIR. Esegui prima una build contenuti/completa." >&2
    exit 1
  fi
  echo "→ Rigenero i canzonieri nella release corrente ($CURRENT_DIR) ..."
  CANZONIERI_OUT_DIR="$CURRENT_DIR/client/pdf/canzonieri" node scripts/generate-canzonieri.mjs
  exit 0
fi

if [ "$MODE" = "redirect" ]; then
  CURRENT_DIR="$OUTPUT_DIR/current"
  if [ ! -f "$CURRENT_DIR/server/entry.mjs" ]; then
    echo "✗ Nessuna release trovata in $CURRENT_DIR. Esegui prima una build contenuti/completa." >&2
    exit 1
  fi
  echo "→ Rigenero i redirect nella release corrente ($CURRENT_DIR) ..."
  node scripts/generate-redirects.mjs "$CURRENT_DIR/_redirects.conf"
  exit 0
fi

if [ "$MODE" = "content" ]; then
  export SKIP_PDF=1
fi

RELEASES_DIR="$OUTPUT_DIR/releases"
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
BUILD_DIR="$RELEASES_DIR/$TIMESTAMP"
KEEP=7

mkdir -p "$BUILD_DIR"

# Le immagini vengono scaricate da Drupal direttamente nella cache sul volume
# (UPLOADS_DIR, vedi assets.ts e compose.*.yml): solo quelle mancanti vengono
# ri-scaricate, e la cache non passa mai da public/ (che Astro copierebbe
# nell'outDir duplicando tutti i media ad ogni build).
UPLOAD_CACHE="${UPLOADS_DIR:?definire UPLOADS_DIR nel compose (es. /app/output/.uploads-cache)}"

phase "Building to $BUILD_DIR ..."
# Granularità giornaliera (niente ora): le pagine invariate restano
# byte-identiche tra build dello stesso giorno, così la cache di
# precompressione qui sotto può riusarne i .br/.gz invece di ricomprimerli.
BUILD_DATE=$(TZ=Europe/Rome date "+%Y.%m.%d") npx astro build --outDir "$BUILD_DIR"

if [ -d "$UPLOAD_CACHE" ]; then
  # Hardlink cache → release: cache e release stanno sullo stesso volume e
  # client/uploads non esiste ancora (i download non passano da public/),
  # quindi cp -al crea l'albero e le N release condividono gli inode invece
  # di duplicare i media. assets.ts scrive i file nuovi come nuovi inode,
  # quindi le release precedenti restano intatte.
  phase "Hardlinking uploads into build dir ($(du -sh "$UPLOAD_CACHE" | cut -f1))..."
  cp -al "$UPLOAD_CACHE" "$BUILD_DIR/client/uploads"
fi

# I canzonieri collettivi vivono fuori dal ciclo di build Astro: li scrive in
# place, con cadenza settimanale, il cron "canzonieri" (vedi sopra, modalità
# "canzonieri") direttamente dentro la release che in quel momento è
# "current". Questa build ne crea però una nuova e sposta lo symlink: senza
# riportarli qui, i canzonieri generati dall'ultimo cron sparirebbero dalla
# release nuova (404) finché il cron non gira di nuovo — indipendentemente
# dall'ordine in cui build-frontend e build-canzonieri vengono lanciati.
# Hardlink dalla release precedente (ancora "current" a questo punto dello script).
PREV_CANZONIERI="$OUTPUT_DIR/current/client/pdf/canzonieri"
if [ -d "$PREV_CANZONIERI" ]; then
  phase "Carrying over canzonieri from previous release ($(du -sh "$PREV_CANZONIERI" | cut -f1))..."
  mkdir -p "$BUILD_DIR/client/pdf"
  cp -al "$PREV_CANZONIERI" "$BUILD_DIR/client/pdf/canzonieri"
fi

# I redirect nginx vivono fuori dal ciclo di build Astro (rigenerati on-demand
# da ./ildeposito.sh build-redirect, modalità "redirect" sopra): la build
# contenuti si limita a portarli avanti dalla release precedente, altrimenti
# la nuova release perderebbe i redirect finché non si rilancia build-redirect
# esplicitamente. Scrive comunque un file vuoto se non esiste ancora nessuna
# release precedente, per non rompere l'`include` in frontend/nginx.conf.
PREV_REDIRECTS="$OUTPUT_DIR/current/_redirects.conf"
if [ -f "$PREV_REDIRECTS" ]; then
  phase "Carrying over _redirects.conf from previous release..."
  cp "$PREV_REDIRECTS" "$BUILD_DIR/_redirects.conf"
else
  phase "Nessun _redirects.conf precedente, scrivo vuoto..."
  echo "# Nessun redirect pubblicato — vedi ./ildeposito.sh build-redirect" > "$BUILD_DIR/_redirects.conf"
fi

# Pagefind indicizza solo la parte statica (client/)
phase "Indexing with Pagefind ..."
npx pagefind --site "$BUILD_DIR/client"

# Precompressione brotli+gzip degli asset testuali: nginx li serve con
# brotli_static/gzip_static senza ricomprimere a runtime (e a livello 10 vs 6).
# q11 vs q10: guadagno di dimensione ~1-2%, ma quasi il doppio del tempo CPU
# sulle build fredde; q10 resta il compromesso migliore.
# Cache per md5 del contenuto sul volume: i file byte-identici a una build
# precedente (vedi BUILD_DATE giornaliera sopra) vengono hardlinkati invece
# di ricompressi; il touch tiene vivi gli entry usati per il pruning finale.
# Escluso pagefind/: i suoi frammenti sono già compressi e serviti raw.
COMPRESS_CACHE="$OUTPUT_DIR/.compress-cache"
mkdir -p "$COMPRESS_CACHE"
export COMPRESS_CACHE
phase "Precompressing static assets (brotli + gzip, cached)..."
find "$BUILD_DIR/client" -type f \
  \( -name '*.html' -o -name '*.css' -o -name '*.js' -o -name '*.mjs' \
     -o -name '*.svg' -o -name '*.xml' -o -name '*.json' -o -name '*.txt' \) \
  -size +256c ! -path '*/pagefind/*' -print0 \
  | xargs -0 -r -P "$(nproc)" -n 16 sh -c '
      for f in "$@"; do
        h=$(md5sum "$f" | cut -d" " -f1)
        if [ -f "$COMPRESS_CACHE/$h.br" ] && [ -f "$COMPRESS_CACHE/$h.gz" ]; then
          ln "$COMPRESS_CACHE/$h.br" "$f.br"
          ln "$COMPRESS_CACHE/$h.gz" "$f.gz"
          touch "$COMPRESS_CACHE/$h.br" "$COMPRESS_CACHE/$h.gz"
        else
          brotli -q 10 -f "$f" && gzip -9 -kf "$f"
          # "|| true": due file con lo stesso contenuto nella stessa wave
          # possono provare a creare lo stesso entry di cache in parallelo.
          ln "$f.br" "$COMPRESS_CACHE/$h.br" 2>/dev/null || true
          ln "$f.gz" "$COMPRESS_CACHE/$h.gz" 2>/dev/null || true
        fi
      done' _

# Pruning: elimina gli entry di cache non riusati da 30 giorni. Gli hardlink
# nelle release restano validi (l'inode sopravvive finché referenziato).
find "$COMPRESS_CACHE" -type f -mtime +30 -delete

phase "Syncing node_modules / swapping release ..."
# Sincronizza node_modules sul volume (solo se package-lock.json è cambiato).
# frontend-api non ha filesystem proprio: Node risolve i moduli risalendo le
# directory fino a trovare $OUTPUT_DIR/node_modules via il symlink nella release.
LOCK_HASH=$(md5sum /app/package-lock.json | cut -d' ' -f1)
LOCK_MARKER="$OUTPUT_DIR/.node_modules_hash"
if [ ! -d "$OUTPUT_DIR/node_modules" ] || [ "$(cat "$LOCK_MARKER" 2>/dev/null)" != "$LOCK_HASH" ]; then
  echo "→ Syncing node_modules to volume (packages changed)..."
  rsync -a --delete /app/node_modules/ "$OUTPUT_DIR/node_modules/"
  echo "$LOCK_HASH" > "$LOCK_MARKER"
else
  echo "→ node_modules unchanged, skipping sync."
fi
ln -sfn "$OUTPUT_DIR/node_modules" "$BUILD_DIR/node_modules"

echo "→ Swapping symlink to $TIMESTAMP ..."
ln -sfn "releases/$TIMESTAMP" "$OUTPUT_DIR/current"

echo "→ Cleaning old releases (keeping last $KEEP) ..."
cd "$RELEASES_DIR"
# shellcheck disable=SC2010
ls -dt */ 2>/dev/null | tail -n +$((KEEP + 1)) | xargs rm -rf

echo "✓ Build $TIMESTAMP live in $(( $(date +%s) - BUILD_T0 ))s."
