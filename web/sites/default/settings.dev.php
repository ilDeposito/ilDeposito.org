<?php

/**
 * DDEV-only development overrides.
 *
 * WHY:
 * - The "Development settings" UI writes to keyvalue storage
 *   (development_settings), not to exported config.
 * - These runtime overrides keep the same dev behavior always active in DDEV.
 */

$dev_services = DRUPAL_ROOT . '/sites/default/services.dev.yml';
if (is_readable($dev_services) && !in_array($dev_services, $settings['container_yamls'] ?? [], TRUE)) {
  $settings['container_yamls'][] = $dev_services;
}

// Equivalent to "Do not cache markup" in Development settings.
$settings['cache']['bins']['render'] = 'cache.backend.null';
$settings['cache']['bins']['dynamic_page_cache'] = 'cache.backend.null';
$settings['cache']['bins']['page'] = 'cache.backend.null';
