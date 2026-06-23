#!/bin/bash

set -e

# Aggiorna il repository git remoto
if ! ddev live git pull > /dev/null 2>&1; then
  echo "[ERRORE] git pull fallito"; exit 1;
else
  echo "[OK] Codice sorgente aggiornato";
fi

# Installa le dipendenze composer
if ! ddev live composer install --ignore-platform-reqs > /dev/null 2>&1; then
  echo "[ERRORE] composer install fallito"; exit 1;
else
  echo "[OK] Dipendenze composer installate";
fi

# Aggiorna il database Drupal
if ! ddev live drush updb -y > /dev/null 2>&1; then
  echo "[ERRORE] drush updb fallito"; exit 1;
else
  echo "[OK] Database aggiornato";
fi

# Importa la configurazione Drupal
if ! ddev live drush cim -y > /dev/null 2>&1; then
  echo "[ERRORE] drush cim fallito"; exit 1;
else
  echo "[OK] Configurazione importata";
fi

# Pulizia cache
if ! ddev live drush cr -y > /dev/null 2>&1; then
  echo "[ERRORE] drush cr fallito"; exit 1;
else
  echo "[OK] Cache pulita";
fi

# Svuota tutti gli indici di ricerca Search API
#if ! ddev live drush search-api-clear > /dev/null 2>&1; then
#  echo "[ERRORE] search-api-clear fallito"; exit 1;
#else
#  echo "[OK] Indici Search API svuotati";
#fi

# Ricostruisce tutti gli indici di ricerca Search API
#if ! ddev live drush search-api-index > /dev/null 2>&1; then
#  echo "[ERRORE] search-api-index fallito"; exit 1;
#else
#  echo "[OK] Indici Search API ricostruiti";
#fi

# Pulizia cache
if ! ddev live drush cr -y > /dev/null 2>&1; then
  echo "[ERRORE] drush cr fallito"; exit 1;
else
  echo "[OK] Cache pulita";
  echo "[OK] Deploy in produzine completato!"
fi