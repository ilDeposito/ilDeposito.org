#!/usr/bin/env bash
set -euo pipefail

DRUPAL_ROOT="/var/www/html/web"
THEME_DIR="${DRUPAL_ROOT}/themes/custom/ildeposito"

echo "=== ilDeposito.org Codespace Setup ==="

# 1. Dipendenze PHP
echo ">>> Composer install..."
composer install --working-dir=/var/www/html --no-interaction

# 2. Attesa MariaDB
echo ">>> Attendo MariaDB..."
for i in $(seq 1 30); do
  if mysql -h mariadb -u drupal -pdrupal -e "SELECT 1" &>/dev/null; then
    echo "MariaDB pronto."
    break
  fi
  if [ "$i" -eq 30 ]; then
    echo "ERRORE: MariaDB non disponibile dopo 60 secondi."
    exit 1
  fi
  echo "  Tentativo $i/30..."
  sleep 2
done

# 3. Installazione Drupal (replica di script/reset.sh)
echo ">>> Installo Drupal minimal..."
cd "$DRUPAL_ROOT"
drush si minimal -y \
  --account-name=admin \
  --account-pass=admin \
  --account-mail=admin@example.com \
  --site-name="ilDeposito.org"

echo ">>> Imposto UUID sito..."
drush cset system.site uuid 541fc72c-bd22-44ca-a91e-f8f697223f94 -y

echo ">>> Importo configurazioni..."
drush cim -y

echo ">>> Aggiorno database..."
drush updb -y

echo ">>> Creo contenuti di default..."
drush ildeposito:create-default-media || echo "WARN: create-default-media non disponibile, salto."

echo ">>> Controllo traduzioni..."
drush locale:check || true

echo ">>> Aggiorno traduzioni..."
drush locale:update || true

echo ">>> Re-importo configurazioni..."
drush cim -y

echo ">>> Indicizzo contenuti..."
drush search-api:index || echo "WARN: indicizzazione Solr fallita, verificare che Solr sia pronto."

echo ">>> Ricostruisco cache..."
drush cr

# 4. Build tema frontend
echo ">>> Installo dipendenze npm del tema..."
cd "$THEME_DIR"
npm install

echo ">>> Build assets di produzione..."
npm run production

echo ""
echo "=== Setup completato ==="
echo "Drupal e' accessibile dalla tab Ports sulla porta 80."
echo "Account admin: admin / admin"
