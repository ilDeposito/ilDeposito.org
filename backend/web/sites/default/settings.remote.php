<?php

/**
 * @file
 * Configurazioni condivise tra staging e produzione.
 */

$databases['default']['default'] = [
  'database' => getenv('DB_NAME') ?: 'drupal',
  'username' => getenv('DB_USER') ?: 'drupal',
  'password' => getenv('DB_PASSWORD') ?: 'drupal',
  'prefix' => '',
  'host' => getenv('DB_HOST') ?: 'mariadb',
  'port' => '3306',
  'isolation_level' => 'READ COMMITTED',
  'driver' => 'mysql',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
];

$settings['container_yamls'][] = DRUPAL_ROOT . '/sites/default/services.prod.yml';

$config['system.logging']['error_level'] = 'hide';
$config['system.performance']['css']['preprocess'] = TRUE;
$config['system.performance']['css']['gzip'] = TRUE;
$config['system.performance']['js']['preprocess'] = TRUE;
$config['system.performance']['js']['gzip'] = TRUE;
$config['system.performance']['response']['gzip'] = TRUE;
$config['system.performance']['cache']['page']['max_age'] = 3600;
$config['views.settings']['ui']['show']['sql_query']['enabled'] = FALSE;
$config['views.settings']['ui']['show']['performance_statistics'] = FALSE;
