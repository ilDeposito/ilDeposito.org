<?php

/**
 * Configurazioni da NON toccare
 */
$settings['hash_salt'] = 'aZQI_jJDgRpolN1-WgTtHsHW-_9NZXcxWezGVtSvPesRio9hLlD1b_iyFB6T04eSmLKaePwCIw';
$settings['update_free_access'] = FALSE;

$settings['config_sync_directory'] = 'sites/default/config';
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';
$settings['file_scan_ignore_directories'] = [
  'node_modules',
  'bower_components',
];
$settings['entity_update_batch_size'] = 50;
$settings['entity_update_backup'] = TRUE;
$settings['migrate_node_migrate_type_classic'] = FALSE;
ini_set('memory_limit', '1024M');

$settings['trusted_host_patterns'] = [
  '^ildeposito-nginx$',
  '^ildeposito.org$',
  '^www\.ildeposito\.org$',
  '^new\.ildeposito\.org$',
  '^backend\.ildeposito\.org$',  
  '^localhost$',
];

if (isset($_SERVER['ILDEPOSITO_ENV'])) {
      include __DIR__ . '/settings.live.php';
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
