<?php

declare(strict_types=1);

namespace Drupal\migrando\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;

/**
 * Source plugin for migrating users from XML.
 *
 * This plugin reads the XML file directly to avoid issues with migrate_plus.
 *
 * @MigrateSource(
 *   id = "utenti_xml",
 *   label = @Translation("Utenti XML Source")
 * )
 */
class Utenti extends SourcePluginBase {

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return 'Utenti XML file';
  }

  /**
   * {@inheritdoc}
   */
  public function getIds(): array {
    return [
      'uid' => [
        'type' => 'string',
        'alias' => 'u',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    return [
      'uid' => $this->t('User ID'),
      'username' => $this->t('Username'),
      'mail' => $this->t('Email'),
      'uuid' => $this->t('UUID'),
      'created' => $this->t('Created timestamp'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator(): \Iterator {
    $path = DRUPAL_ROOT . '/../' . $this->configuration['path'];
    if (!file_exists($path)) {
      throw new \InvalidArgumentException("File not found: {$path}");
    }

    $xml_string = file_get_contents($path);
    $xml = new \SimpleXMLElement($xml_string);

    $data = [];
    foreach ($xml->item as $item) {
      $data[] = [
        'uid' => (string) $item->uid,
        'username' => (string) $item->username,
        'mail' => (string) $item->email,
        'uuid' => (string) $item->uuid,
        'created' => (string) $item->created,
      ];
    }
    return new \ArrayIterator($data);
  }

}
