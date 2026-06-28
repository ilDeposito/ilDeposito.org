#!/bin/sh
set -e
chown -R node:node /app/output
mkdir -p /app/.astro
chown -R node:node /app/.astro
exec su-exec node "$@"
