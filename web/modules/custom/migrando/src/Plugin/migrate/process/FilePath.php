<?php

/**
 * @file
 * Contains \Drupal\migrando\Plugin\migrate\process\DateSubstr.
 */
namespace Drupal\migrando\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Process latitude and longitude and return the value for the D8 geofield.
 *
 * @MigrateProcessPlugin(
 *   id = "file_path"
 * )
 */
class FilePath extends ProcessPluginBase {
  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $explode = explode('|', $value);
    $path = 'public://immagini/' . $explode[0] . '/' . substr($explode[1], 9);
    return $path;
  }
}
