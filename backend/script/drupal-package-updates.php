<?php

declare(strict_types=1);

if ($argc !== 3) {
  fwrite(STDERR, "Uso: php drupal-package-updates.php <composer-outdated.json> <composer.lock>\n");
  exit(1);
}

$outdated = json_decode((string) file_get_contents($argv[1]), TRUE, 512, JSON_THROW_ON_ERROR);
$lock = json_decode((string) file_get_contents($argv[2]), TRUE, 512, JSON_THROW_ON_ERROR);
$locked = [];

foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
  if (is_array($package) && isset($package['name'], $package['version'])) {
    $locked[$package['name']] = $package['version'];
  }
}

$lines = [];
foreach ($outdated['installed'] ?? [] as $package) {
  $name = (string) ($package['name'] ?? '');
  if (!str_starts_with($name, 'drupal/')) {
    continue;
  }
  $before = (string) ($package['version'] ?? 'sconosciuta');
  $after = $locked[$name] ?? (string) ($package['latest'] ?? 'sconosciuta');
  $lines[] = sprintf('%s %s → %s', $name, $before, $after);
}

sort($lines, SORT_NATURAL | SORT_FLAG_CASE);
print implode(PHP_EOL, $lines) . (count($lines) ? PHP_EOL : '');
