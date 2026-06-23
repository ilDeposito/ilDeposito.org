<?php

/**
 * @file
 * Contains \Drupal\migrando\Plugin\migrate\process\GetNode.
 */
namespace Drupal\migrando\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Process latitude and longitude and return the value for the D8 geofield.
 *
 * @MigrateProcessPlugin(
 *   id = "get_node"
 * )
 */
class GetNode extends ProcessPluginBase {
  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $query = \Drupal::entityQuery('node')
      ->condition('field_old_id', $value)
      ->accessCheck(TRUE);
    $result = $query->execute();
    if (count($result) == 0) {
      $a = 1;
      return;
    }
    return reset($result);
  }

}
