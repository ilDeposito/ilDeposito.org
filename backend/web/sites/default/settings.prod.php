<?php

/**
 * @file
 * Override per ambiente produzione.
 */

require __DIR__ . '/settings.remote.php';

$settings['trusted_host_patterns'] = [
  '^admin\.ildeposito\.org$',
  '^ildeposito-drupal-nginx$',
  '^drupal-api$',
  '^localhost$',
];
