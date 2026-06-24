<?php

/**
 * @file
 * Configurazioni condivise tra staging e produzione.
 */

$databases['default']['default'] = [
  'database' => $_SERVER['DB_NAME'] ?? 'drupal',
  'username' => $_SERVER['DB_USER'] ?? 'drupal',
  'password' => $_SERVER['DB_PASSWORD'] ?? 'drupal',
  'prefix' => '',
  'host' => $_SERVER['DB_HOST'] ?? 'mariadb',
  'port' => '3306',
  'isolation_level' => 'READ COMMITTED',
  'driver' => 'mysql',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
];

$settings['container_yamls'][] = DRUPAL_ROOT . '/sites/default/services.prod.yml';

$config['reroute_email.settings']['enable'] = FALSE;
$config['system.logging']['error_level'] = 'hide';
$config['system.performance']['css']['preprocess'] = TRUE;
$config['system.performance']['css']['gzip'] = TRUE;
$config['system.performance']['js']['preprocess'] = TRUE;
$config['system.performance']['js']['gzip'] = TRUE;
$config['system.performance']['response']['gzip'] = TRUE;
$config['views.settings']['ui']['show']['sql_query']['enabled'] = FALSE;
$config['views.settings']['ui']['show']['performance_statistics'] = FALSE;
