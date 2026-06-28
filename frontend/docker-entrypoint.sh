#!/bin/sh
set -e

OUTPUT_DIR="/app/output"
RELEASES_DIR="$OUTPUT_DIR/releases"
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
BUILD_DIR="$RELEASES_DIR/$TIMESTAMP"
KEEP=7

mkdir -p "$BUILD_DIR"

echo "→ Building to $BUILD_DIR ..."
npx astro build --outDir "$BUILD_DIR"

# Le immagini vengono scaricate da Drupal in public/uploads/ durante la build,
# ma DOPO che Astro ha già copiato public/ nell'outDir. Vanno copiate manualmente.
if [ -d /app/public/uploads ]; then
  echo "→ Copying uploads to build dir ..."
  cp -r /app/public/uploads "$BUILD_DIR/"
fi

npx pagefind --site "$BUILD_DIR"

COMPLETED=$(TZ=Europe/Rome date +%Y.%m.%d\ -\ %H:%M:%S)
find "$BUILD_DIR" -name '*.html' -exec sed -i "s/build dev/Last build: $COMPLETED/" {} +

echo "→ Swapping symlink to $TIMESTAMP ..."
ln -sfn "releases/$TIMESTAMP" "$OUTPUT_DIR/current"

echo "→ Cleaning old releases (keeping last $KEEP) ..."
cd "$RELEASES_DIR"
# shellcheck disable=SC2010
ls -dt */ 2>/dev/null | tail -n +$((KEEP + 1)) | xargs rm -rf

echo "✓ Build $TIMESTAMP live."
