<?php

/**
 * @file
 * Override per ambiente staging.
 */

require __DIR__ . '/settings.remote.php';

$settings['trusted_host_patterns'] = [
  '^ildeposito$',
  '^admin-stage\.ildeposito\.org$',
  '^ildeposito-drupal-nginx$',
  '^drupal-api$',
  '^localhost$',
];
