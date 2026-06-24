<?php

/**
 * @file
 * Override per ambiente staging.
 */

require __DIR__ . '/settings.remote.php';

$config['reroute_email.settings']['enable'] = TRUE;
$config['reroute_email.settings']['address'] = 'sergio.durzu@gmail.com';

$settings['trusted_host_patterns'] = [
  '^ildeposito-stage$',
  '^admin-stage\.ildeposito\.org$',
  '^ildeposito-drupal-nginx$',
  '^drupal-api$',
  '^localhost$',
];
