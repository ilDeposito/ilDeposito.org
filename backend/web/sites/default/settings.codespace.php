<?php

/**
 * @file
 * Configurazioni per ambiente GitHub Codespaces.
 */

$databases['default']['default'] = [
  'database' => 'drupal',
  'username' => 'drupal',
  'password' => 'drupal',
  'prefix' => '',
  'host' => 'mariadb',
  'port' => '3306',
  'isolation_level' => 'READ COMMITTED',
  'driver' => 'mysql',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
];

// Trusted host pattern per Codespaces.
$settings['trusted_host_patterns'][] = '\.app\.github\.dev$';

// Memcached.
$settings['memcache']['servers'] = ['memcached:11211' => 'default'];
$settings['memcache']['bins'] = ['default' => 'default'];
$settings['memcache']['key_prefix'] = '';

// Includi settings di sviluppo (debug, twig debug, cache disabilitata).
$dev_settings = __DIR__ . '/settings.dev.php';
if (is_readable($dev_settings)) {
  require $dev_settings;
}
