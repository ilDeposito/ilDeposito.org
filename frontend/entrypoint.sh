#!/bin/sh
set -e
chown -R node:node /app/output
exec su-exec node "$@"
