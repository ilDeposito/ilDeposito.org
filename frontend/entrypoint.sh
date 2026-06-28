#!/bin/sh
set -e
chown node:node /app/output
exec su-exec node "$@"
