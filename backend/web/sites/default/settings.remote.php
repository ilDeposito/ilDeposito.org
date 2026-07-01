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

// Nginx è raggiungibile solo tramite Caddy (rete Docker "caddy", nessuna
// porta pubblicata): fidarsi dell'header X-Forwarded-* del solo peer diretto
// per far rilevare a Drupal lo schema/IP reali del client dietro il proxy.
$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_addresses'] = [$_SERVER['REMOTE_ADDR'] ?? ''];
$settings['reverse_proxy_trusted_headers'] = \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_ALL;

$config['system.logging']['error_level'] = 'hide';
$config['system.performance']['css']['preprocess'] = TRUE;
$config['system.performance']['css']['gzip'] = TRUE;
$config['system.performance']['js']['preprocess'] = TRUE;
$config['system.performance']['js']['gzip'] = TRUE;
$config['system.performance']['response']['gzip'] = TRUE;
$config['system.performance']['cache']['page']['max_age'] = 3600;
$config['views.settings']['ui']['show']['sql_query']['enabled'] = FALSE;
$config['views.settings']['ui']['show']['performance_statistics'] = FALSE;

// SMTP via Symfony Mailer (core).
if (getenv('SMTP_HOST')) {
  $config['system.mail']['interface']['default'] = 'symfony_mailer';
  $config['system.mail']['mailer_dsn'] = [
    'scheme' => 'smtp',
    'host' => getenv('SMTP_HOST'),
    'port' => (int) (getenv('SMTP_PORT') ?: 587),
    'user' => getenv('SMTP_USER') ?: NULL,
    'password' => getenv('SMTP_PASS') ?: NULL,
    'options' => [],
  ];
}

if (getenv('MAIL_FROM')) {
  $config['system.site']['mail'] = getenv('MAIL_FROM');
}

// JSON:API in scrittura (filtrato dal modulo ildeposito_build).
// @todo Abilitare quando il frontend sarà pronto.
// $config['jsonapi.settings']['read_only'] = FALSE;
