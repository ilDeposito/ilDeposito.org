<?php

// @todo DEBUG TEMPORANEO — sposta qui il log da settings.remote.php: quello
// scatta solo se ILDEPOSITO_ENV === 'prod' fa entrare nel branch che include
// settings.remote.php, quindi non cattura richieste dove quella env risulta
// mancante/diversa. Questo gira PRIMA di qualunque condizionale, su ogni
// richiesta che arriva fin qui. Rimuovere insieme all'altro debug log una
// volta chiuso il bug "trusted_host_patterns" intermittente.
error_log(sprintf(
  "[%s] SETTINGSDEBUG uri=%s host=%s ildeposito_env=%s xfh=%s xfp=%s remote_addr=%s\n",
  date('H:i:s'),
  $_SERVER['REQUEST_URI'] ?? '?',
  $_SERVER['HTTP_HOST'] ?? 'MISSING',
  $_SERVER['ILDEPOSITO_ENV'] ?? 'MISSING',
  $_SERVER['HTTP_X_FORWARDED_HOST'] ?? 'MISSING',
  $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'MISSING',
  $_SERVER['REMOTE_ADDR'] ?? 'MISSING'
), 3, '/tmp/settingsdebug.log');

/**
 * Configurazioni da NON toccare
 */
$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') ?: '';
$settings['update_free_access'] = FALSE;

$settings['config_sync_directory'] = 'sites/default/config';
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/monolog.services.yml';
$settings['file_scan_ignore_directories'] = [
  'node_modules',
  'bower_components',
];
$settings['entity_update_batch_size'] = 50;
$settings['entity_update_backup'] = TRUE;
$settings['migrate_node_migrate_type_classic'] = FALSE;
ini_set('memory_limit', PHP_SAPI === 'cli' ? '1024M' : '256M');

// GitHub App per il modulo ildeposito_build (trigger workflow_dispatch).
$settings['ildeposito_build_github_app_id'] = getenv('GITHUB_APP_ID') ?: '';
$settings['ildeposito_build_github_installation_id'] = getenv('GITHUB_APP_INSTALLATION_ID') ?: '';

// Umami self-hosted per il modulo ildeposito_stats (import statistiche).
// Self-hosted Umami non supporta API key (solo Umami Cloud): serve utente/password.
$settings['ildeposito_stats_umami_api_url'] = getenv('UMAMI_API_URL') ?: '';
$settings['ildeposito_stats_umami_username'] = getenv('UMAMI_USERNAME') ?: '';
$settings['ildeposito_stats_umami_password'] = getenv('UMAMI_PASSWORD') ?: '';
$settings['ildeposito_stats_umami_website_id'] = getenv('UMAMI_WEBSITE_ID') ?: '';

// Webhook Make.com per il post automatico su FB degli eventi con
// anniversario oggi (ildeposito_utils, drush ildeposito:fb-post). Va
// impostato SOLO nel .env di produzione (non sotto git): se assente il
// comando resta un no-op, così stage non posta per sbaglio su FB.
$settings['ildeposito_utils_fbpost_webhook_url'] = getenv('FBPOST_WEBHOOK_URL') ?: '';

$settings['enable_html5_validation'] = FALSE;

$settings['trusted_host_patterns'] = [
  '^ildeposito-nginx$',
  '^ildeposito\.org$',
  '^www\.ildeposito\.org$',
  '^new\.ildeposito\.org$',
  '^backend\.ildeposito\.org$',
  '^admin-stage\.ildeposito\.org$',
  '^admin\.ildeposito\.org$',
  '^localhost$',
];

if (isset($_SERVER['ILDEPOSITO_ENV']) && $_SERVER['ILDEPOSITO_ENV'] === 'prod') {
  include __DIR__ . '/settings.prod.php';
} elseif (isset($_SERVER['ILDEPOSITO_ENV']) && $_SERVER['ILDEPOSITO_ENV'] === 'stage') {
  include __DIR__ . '/settings.stage.php';
} else {
  $ddev_settings = __DIR__ . '/settings.ddev.php';
  $dev_settings = __DIR__ . '/settings.dev.php';
  if (getenv('IS_DDEV_PROJECT') == 'true' && is_readable($ddev_settings)) {
    require $ddev_settings;
    if (is_readable($dev_settings)) {
      require $dev_settings;
    }
  }
}

// Dopo gli include per ambiente: si attiva da solo dove REDIS_HOST è definito.
include __DIR__ . '/settings.redis.php';
