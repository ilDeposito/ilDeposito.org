<?php

declare(strict_types=1);

namespace Drupal\migrando\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Builds a public:// image destination URI following immagini/Y/m.
 *
 * Reproduces the pattern configured on
 * field.field.media.image.field_media_image (file_directory:
 * 'immagini/[date:custom:Y]/[date:custom:m]'), independently of whatever
 * legacy directory the source file lived in. Legacy imports have file paths
 * that don't match this pattern (e.g. "2018-09/...", "inline-images/...");
 * recomputing the directory from the file's created timestamp keeps every
 * migrated file consistent with the configured token pattern.
 *
 * Source is [created timestamp, filename]. Falls back to the current time
 * when the timestamp is missing or invalid, so every file still lands under
 * immagini/Y/m instead of being skipped.
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
    [$created, $filename] = $value;
    $timestamp = is_numeric($created) ? (int) $created : time();

    return sprintf('public://immagini/%s/%s/%s', date('Y', $timestamp), date('m', $timestamp), $filename);
  }

}
