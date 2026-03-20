#!/usr/bin/env bash
set -euo pipefail

# Uso: ./ildeposito.sh <prod|stage> <comando> [args...]
# Esempio: ./ildeposito.sh prod up -d
#          ./ildeposito.sh stage logs -f php

ENV=${1:?"Uso: ./ildeposito.sh <prod|stage> <comando> [args...]"}
shift

# Validazione ambiente
if [[ "$ENV" != "prod" && "$ENV" != "stage" ]]; then
  echo "❌ Ambiente '$ENV' non valido. Usa 'prod' o 'stage'."
  exit 1
fi

# Comando richiesto
CMD=${1:?"Uso: ./ildeposito.sh $ENV <comando> [args...]"}

# Protezione produzione
if [[ "$ENV" == "prod" && "$CMD" == "down" ]]; then
  echo "⛔ Comando 'down' bloccato in produzione."
  echo "   Se sei sicuro, usa direttamente docker compose."
  exit 1
fi

docker compose -p "ildeposito_${ENV}" \
  -f docker-compose.yml \
  -f "docker-compose-${ENV}.yml" \
  "$@"