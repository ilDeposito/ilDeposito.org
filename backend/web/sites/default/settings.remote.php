<?php

/**
 * @file
 * Configurazioni condivise tra staging e produzione.
 */

// @todo DEBUG TEMPORANEO — rimuovere dopo aver diagnosticato il bug
// "trusted_host_patterns" intermittente. Scrive su stderr di PHP-FPM
// (docker compose logs php) prima che Drupal faccia il check, per vedere
// gli header host reali ricevuti su una richiesta che fallisce.
error_log(sprintf(
  "[%s] HOSTDEBUG uri=%s host=%s xfh=%s xfp=%s fwd=%s remote_addr=%s\n",
  date('H:i:s'),
  $_SERVER['REQUEST_URI'] ?? '?',
  $_SERVER['HTTP_HOST'] ?? 'MISSING',
  $_SERVER['HTTP_X_FORWARDED_HOST'] ?? 'MISSING',
  $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'MISSING',
  $_SERVER['HTTP_FORWARDED'] ?? 'MISSING',
  $_SERVER['REMOTE_ADDR'] ?? 'MISSING'
), 3, '/tmp/hostdebug.log');

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

// Caddy termina il TLS e fa da reverse proxy verso nginx (Caddy->nginx è un
// hop interno in chiaro): senza reverse_proxy, Drupal ignora l'header
// X-Forwarded-Proto e genera URL assolute con scheme http, non https. Prima
// non se ne accorgeva nessuno (nessun consumatore controllava lo scheme),
// ma rompe l'OIDC di Authelia: il redirect_uri generato non combacia più
// (byte a byte) con quello registrato come client su Authelia (https), che
// rifiuta la richiesta con "redirect_uri does not match".
// 172.16.0.0/12 copre il range di IP che Docker assegna alle bridge network
// per progetto (qui "backend-internal"): nginx è l'unico altro membro di
// quella rete, isolata e non raggiungibile dall'host esterno, quindi non è
// uno spoofing risk fidarsi di chiunque vi appartenga.
$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_addresses'] = ['172.16.0.0/12'];
// Il default di Drupal fida anche X-Forwarded-Host/Port/Forwarded (non
// sicuro, vedi default.settings.php): qui serve solo Proto (per lo scheme
// https, vedi sopra) + For (IP reale già gestito comunque da ngx_realip
// lato nginx). Senza questo, un Host inatteso in quegli header basta a far
// fallire il trusted_host_patterns check anche a richiesta legittima.
$settings['reverse_proxy_trusted_headers'] = \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO;

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
// placeholder fittizi, sovrascritti qui se l'env è valorizzata: se in un
// ambiente OPENID_CONNECT_AUTHELIA_CLIENT_ID/SECRET non sono impostate nel
// .env, il client resta con i placeholder (login SSO non funzionante finché
// non si valorizzano).
if (getenv('OPENID_CONNECT_AUTHELIA_CLIENT_ID')) {
  $config['openid_connect.client.authelia']['settings']['client_id'] = getenv('OPENID_CONNECT_AUTHELIA_CLIENT_ID');
}
if (getenv('OPENID_CONNECT_AUTHELIA_CLIENT_SECRET')) {
  $config['openid_connect.client.authelia']['settings']['client_secret'] = getenv('OPENID_CONNECT_AUTHELIA_CLIENT_SECRET');
}

// JSON:API in scrittura (filtrato dal modulo ildeposito_build).
// @todo Abilitare quando il frontend sarà pronto.
// $config['jsonapi.settings']['read_only'] = FALSE;
