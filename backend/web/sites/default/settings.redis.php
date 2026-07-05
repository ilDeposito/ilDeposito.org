<?php

/**
 * @file
 * Cache su Redis — attiva solo dove REDIS_HOST è definito.
 *
 * Gli ambienti senza REDIS_HOST (o senza l'estensione phpredis)
 * continuano a usare la cache su database senza altre modifiche.
 */

use Drupal\Core\Installer\InstallerKernel;

if (getenv('REDIS_HOST')
  && extension_loaded('redis')
  && class_exists('Drupal\redis\ClientFactory')
  && !InstallerKernel::installationAttempted()
) {
  $settings['redis.connection']['interface'] = 'PhpRedis';
  $settings['redis.connection']['host'] = getenv('REDIS_HOST');
  $settings['redis.connection']['port'] = (int) (getenv('REDIS_PORT') ?: 6379);

  $settings['cache']['default'] = 'cache.backend.redis';

  // Con maxmemory-policy allkeys-lru un form in cache può essere evitto
  // a metà compilazione: il bin form resta su database.
  $settings['cache']['bins']['form'] = 'cache.backend.database';

  // Prefisso per ambiente: evita collisioni se due ambienti
  // condividessero la stessa istanza Redis.
  $settings['cache_prefix'] = 'ildeposito_' . (getenv('ILDEPOSITO_ENV') ?: 'local');

  // Lock, flood e cache-tags checksum su Redis.
  $settings['container_yamls'][] = 'modules/contrib/redis/example.services.yml';

  // Rende disponibili i servizi anche prima che il modulo sia abilitato
  // (es. durante drush cim/updatedb al primo deploy).
  $settings['container_yamls'][] = 'modules/contrib/redis/redis.services.yml';
  $class_loader->addPsr4('Drupal\\redis\\', 'modules/contrib/redis/src');

  // Anche la cache del container su Redis: viene letta prima che il
  // container stesso esista, quindi i servizi vanno definiti a mano.
  $settings['bootstrap_container_definition'] = [
    'parameters' => [],
    'services' => [
      'redis.factory' => [
        'class' => 'Drupal\redis\ClientFactory',
      ],
      'cache.backend.redis' => [
        'class' => 'Drupal\redis\Cache\CacheBackendFactory',
        'arguments' => ['@redis.factory', '@cache_tags_provider.container', '@serialization.phpserialize'],
      ],
      'cache.container' => [
        'class' => '\Drupal\redis\Cache\PhpRedis',
        'factory' => ['@cache.backend.redis', 'get'],
        'arguments' => ['container'],
      ],
      'cache_tags_provider.container' => [
        'class' => 'Drupal\redis\Cache\RedisCacheTagsChecksum',
        'arguments' => ['@redis.factory'],
      ],
      'serialization.phpserialize' => [
        'class' => 'Drupal\Component\Serialization\PhpSerialize',
      ],
    ],
  ];
}
