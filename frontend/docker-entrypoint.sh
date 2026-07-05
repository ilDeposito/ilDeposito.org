#!/bin/sh
set -e

# Modalità: full (default, contenuti + pdf) | content (contenuti, no pdf) |
# pdf (rigenera solo i pdf nella release "current" già live, in-place).
MODE="${1:-full}"
OUTPUT_DIR="/app/output"

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

if [ "$MODE" = "content" ]; then
  export SKIP_PDF=1
fi

RELEASES_DIR="$OUTPUT_DIR/releases"
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
BUILD_DIR="$RELEASES_DIR/$TIMESTAMP"
KEEP=7

mkdir -p "$BUILD_DIR"

# Ripristina cache immagini dal volume (evita ri-download da Drupal)
UPLOAD_CACHE="$OUTPUT_DIR/.uploads-cache"
if [ -d "$UPLOAD_CACHE" ]; then
  echo "→ Restoring image cache ($(du -sh "$UPLOAD_CACHE" | cut -f1))..."
  mkdir -p /app/public/uploads
  rsync -a "$UPLOAD_CACHE/" /app/public/uploads/
fi

echo "→ Building to $BUILD_DIR ..."
BUILD_DATE=$(TZ=Europe/Rome date "+%Y.%m.%d - %H:%M:%S") npx astro build --outDir "$BUILD_DIR"

# Le immagini vengono scaricate da Drupal in public/uploads/ durante la build,
# ma DOPO che Astro ha già copiato public/ nell'outDir. Vanno copiate manualmente.
if [ -d /app/public/uploads ]; then
  # Aggiorna prima la cache sul volume, poi hardlink cache → release: cache e
  # release stanno sullo stesso volume, quindi le N release condividono gli
  # inode invece di duplicare i media. rsync scrive i file aggiornati come
  # nuovi inode (tmp+rename), quindi le release precedenti restano intatte.
  echo "→ Updating image cache..."
  mkdir -p "$UPLOAD_CACHE"
  rsync -a --delete /app/public/uploads/ "$UPLOAD_CACHE/"

  echo "→ Hardlinking uploads into build dir ..."
  # I file statici sono in client/ (output static + adapter node)
  cp -al "$UPLOAD_CACHE" "$BUILD_DIR/client/uploads"
fi

# Pagefind indicizza solo la parte statica (client/)
npx pagefind --site "$BUILD_DIR/client"

# Precompressione brotli+gzip degli asset testuali: nginx li serve con
# brotli_static/gzip_static senza ricomprimere a runtime (e a livello 11 vs 6).
# Escluso pagefind/: i suoi frammenti sono già compressi e serviti raw.
echo "→ Precompressing static assets (brotli + gzip)..."
find "$BUILD_DIR/client" -type f \
  \( -name '*.html' -o -name '*.css' -o -name '*.js' -o -name '*.mjs' \
     -o -name '*.svg' -o -name '*.xml' -o -name '*.json' -o -name '*.txt' \) \
  -size +256c ! -path '*/pagefind/*' -print0 \
  | xargs -0 -r -P "$(nproc)" -n 16 sh -c 'for f in "$@"; do brotli -q 11 -f "$f" && gzip -9 -kf "$f"; done' _

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

echo "✓ Build $TIMESTAMP live."
