<?php

declare(strict_types=1);

namespace Drupal\ildeposito_contatti\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Abilita la scrittura JSON:API a runtime.
 *
 * jsonapi.settings.read_only è TRUE nell'export config (default sicuro), ma
 * questo override lo disabilita affinché le rotte POST/PATCH/DELETE vengano
 * registrate da Drupal. La protezione effettiva rimane in due livelli:
 *   1. JsonApiWriteFirewall (ildeposito_utils) — blocca gli anonimi
 *   2. Access handler dell'entity — enforza i permessi per tipo
 */
final class JsonApiWriteOverride implements ConfigFactoryOverrideInterface {

  public function loadOverrides($names): array {
    if (in_array('jsonapi.settings', $names, TRUE)) {
      return ['jsonapi.settings' => ['read_only' => FALSE]];
    }
    return [];
  }

  public function getCacheSuffix(): string {
    return 'ildeposito_contatti.jsonapi_write';
  }

  public function getCacheableMetadata($name): CacheableMetadata {
    return new CacheableMetadata();
  }

  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

}
