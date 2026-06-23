<?php

/**
 * @file
 * Contains \Drupal\migrando\Plugin\migrate\process\GetMigrationId.
 */
namespace Drupal\migrando\Plugin\migrate\process;

use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Process latitude and longitude and return the value for the D8 geofield.
 *
 * @MigrateProcessPlugin(
 *   id = "get_migration_id"
 * )
 */
class GetMigrationId extends ProcessPluginBase {
  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (empty($this->configuration['importer'])) {
      throw new MigrateException('importer is empty');
    }

    $map_table = 'migrate_map_' . $this->configuration['importer'];
    $query = \Drupal::database()->select($map_table, 'm')
      ->fields('m', ['destid1'])
      ->condition('m.sourceid1', $value);
    $result = $query->execute()->fetchCol();

    if (!empty($result)) {
      $entity_id = reset($result);
      return $entity_id;
    } else {
      return TRUE;
    }
  }
}
