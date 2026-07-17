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

// sites/default è gestita da git + composer scaffold: senza questo flag il
// check runtime del status report la re-indurisce a 555 (chmod attivo, vedi
// SystemRequirementsHooks) e il git reset del deploy fallisce con
// "unable to unlink". La protezione reale resta: PHP-FPM (www-data, uid 82)
// non è owner dei file sul bind mount e non può comunque scriverli.
$settings['skip_permissions_hardening'] = TRUE;

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

// SSO Authelia (openid_connect) — client_id/secret reali SOLO da env, mai in
// config/sync (che è su git pubblico). Il file YAML esportato contiene
// placeholder fittizi, sovrascritti qui se l'env è valorizzata (prod non ha
// ancora l'env, quindi il client resta con i placeholder finché non lo si
// abilita anche lì).
if (getenv('OPENID_CONNECT_AUTHELIA_CLIENT_ID')) {
  $config['openid_connect.client.authelia']['settings']['client_id'] = getenv('OPENID_CONNECT_AUTHELIA_CLIENT_ID');
}
if (getenv('OPENID_CONNECT_AUTHELIA_CLIENT_SECRET')) {
  $config['openid_connect.client.authelia']['settings']['client_secret'] = getenv('OPENID_CONNECT_AUTHELIA_CLIENT_SECRET');
}

// JSON:API in scrittura (filtrato dal modulo ildeposito_build).
// @todo Abilitare quando il frontend sarà pronto.
// $config['jsonapi.settings']['read_only'] = FALSE;
