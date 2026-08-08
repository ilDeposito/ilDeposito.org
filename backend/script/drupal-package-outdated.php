<?php

declare(strict_types=1);

if ($argc !== 2) {
  fwrite(STDERR, "Uso: php drupal-package-outdated.php <composer-outdated.json>\n");
  exit(1);
}

$data = json_decode((string) file_get_contents($argv[1]), TRUE, 512, JSON_THROW_ON_ERROR);
$count = 0;

foreach ($data['installed'] ?? [] as $package) {
  if (is_array($package) && str_starts_with((string) ($package['name'] ?? ''), 'drupal/')) {
    $count++;
  }
}

print $count . PHP_EOL;
