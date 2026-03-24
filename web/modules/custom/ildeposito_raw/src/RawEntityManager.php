<?php

namespace Drupal\ildeposito_raw;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\file\FileInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Service per la gestione dei dati raw delle entità.
 */
class RawEntityManager implements RawEntityManagerInterface {

  /**
   * Configurazione cached per la request.
   */
  protected ?array $cachedSettings = NULL;

  /**
   * Cache tags computati nell'ultima chiamata a getRawData.
   */
  protected array $lastCacheTags = [];

  /**
   * Cache tags raccolti durante la costruzione dei dati raw.
   */
  protected array $buildCacheTags = [];

  /**
   * Timezone cached per evitare ricreazione ripetuta.
   */
  protected ?\DateTimeZone $timezone = NULL;

  /**
   * Costruttore.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly CacheBackendInterface $cache,
    protected readonly TimeInterface $time,
    protected readonly LanguageManagerInterface $languageManager,
    protected readonly FileUrlGeneratorInterface $fileUrlGenerator,
    protected readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Restituisce la configurazione del modulo (cached per la request).
   *
   * @return array
   *   Le impostazioni del modulo.
   */
  protected function getSettings(): array {
    if ($this->cachedSettings === NULL) {
      $config = $this->configFactory->get('ildeposito_raw.settings');
      $raw_entities = $config->get('raw_entities') ?? [];

      // Pre-calcola un indice per lookup O(1) in shouldProcessRaw()
      // e isEntityTypeConfigured(), evitando iterazioni lineari ad ogni render.
      $index = [];
      foreach ($raw_entities as $entry) {
        $et = $entry['entity_type'] ?? '';
        foreach ($entry['bundles'] ?? [] as $bundle) {
          foreach ($entry['view_modes'] ?? [] as $vm) {
            $index[$et][$bundle][$vm] = TRUE;
          }
        }
      }

      $this->cachedSettings = [
        'raw_entities' => $raw_entities,
        'cache_max_age' => $config->get('cache_max_age'),
        'index' => $index,
      ];
    }
    return $this->cachedSettings;
  }

  /**
   * Restituisce il max-age dalla configurazione.
   *
   * @return int
   *   Il valore max-age per la cache.
   */
  public function getCacheMaxAge(): int {
    $settings = $this->getSettings();
    $max_age = $settings['cache_max_age'];
    return ($max_age === NULL) ? Cache::PERMANENT : (int) $max_age;
  }

  /**
   * Verifica se un tipo di entità è configurato per l'elaborazione raw.
   *
   * @param string $entity_type_id
   *   L'ID del tipo di entità.
   *
   * @return bool
   *   TRUE se il tipo di entità è configurato.
   */
  public function isEntityTypeConfigured(string $entity_type_id): bool {
    return isset($this->getSettings()['index'][$entity_type_id]);
  }

  /**
   * Restituisce i cache tags computati nell'ultima chiamata a getRawData.
   *
   * @return array
   *   I cache tags.
   */
  public function getLastCacheTags(): array {
    return $this->lastCacheTags;
  }

  /**
   * Verifica se l'entità deve essere elaborata in modalità raw.
   */
  public function shouldProcessRaw(EntityInterface $entity, string $view_mode = 'default'): bool {
    $index = $this->getSettings()['index'];
    return isset($index[$entity->getEntityTypeId()][$entity->bundle()][$view_mode]);
  }

  /**
   * Genera i dati raw per un'entità, con caching nella cache bin dedicata.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   L'entità da elaborare.
   *
   * @return array
   *   I dati raw dell'entità.
   */
  public function getRawData(EntityInterface $entity): array {
    // Reset dei cache tags per evitare dati stale da chiamate precedenti.
    $this->lastCacheTags = [];

    if (!$entity instanceof ContentEntityInterface) {
      return [];
    }

    $langcode = $entity->language()->getId();
    // Il CID è intenzionalmente indipendente dal view_mode poiché
    // buildRawData() produce dati identici per ogni view mode.
    $cid = "ildeposito_raw:{$entity->getEntityTypeId()}:{$entity->id()}:{$langcode}";

    $cached = $this->cache->get($cid);
    if ($cached) {
      // Recupera i cache tags direttamente dal cache item,
      // evitando di ricalcolarli iterando tutti i campi.
      $this->lastCacheTags = $cached->tags ?? [];
      return $cached->data;
    }

    // Inizializza il collettore di cache tags con i tags dell'entità.
    // I tags delle entità referenziate vengono aggiunti durante processField().
    $this->buildCacheTags = $entity->getCacheTags();

    $data = $this->buildRawData($entity);

    // I cache tags sono stati raccolti durante buildRawData/processField.
    // Includi sempre i tags custom per coerenza tra cache hit e miss.
    $this->lastCacheTags = Cache::mergeTags($this->buildCacheTags, [
      'ildeposito_raw:entity:' . $entity->getEntityTypeId(),
      'config:ildeposito_raw.settings',
    ]);

    $max_age = $this->getCacheMaxAge();
    $expire = ($max_age === Cache::PERMANENT) ? Cache::PERMANENT : $this->time->getRequestTime() + $max_age;

    $this->cache->set($cid, $data, $expire, $this->lastCacheTags);

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function getRawDataWithTags(EntityInterface $entity): array {
    $data = $this->getRawData($entity);
    return [
      'data' => $data,
      'tags' => $this->lastCacheTags,
    ];
  }

  /**
   * Raccoglie tutti i cache tags necessari, inclusi quelli delle entità referenziate.
   *
   * @deprecated in ildeposito_raw:1.1.0 e verrà rimosso in ildeposito_raw:2.0.0.
   *   I cache tags vengono ora raccolti automaticamente durante getRawData()
   *   tramite processField(). Usare getRawDataWithTags() per ottenere dati e
   *   tags in modo atomico, oppure getLastCacheTags() dopo getRawData().
   *
   * @internal
   */
  public function collectCacheTags(FieldableEntityInterface $entity): array {
    @trigger_error('collectCacheTags() is deprecated in ildeposito_raw:1.1.0 and is removed from ildeposito_raw:2.0.0. Use getRawDataWithTags() or getLastCacheTags() after getRawData() instead. See https://www.drupal.org/node/0000000', E_USER_DEPRECATED);
    $cache_tags = $entity->getCacheTags();

    foreach ($entity->getFields() as $field) {
      $field_type = $field->getFieldDefinition()->getType();

      // Gestione delle entità referenziate (include file e image).
      // Usa referencedEntities() per caricare in batch con loadMultiple().
      if ($field instanceof EntityReferenceFieldItemListInterface) {
        foreach ($field->referencedEntities() as $referenced_entity) {
          $cache_tags = Cache::mergeTags($cache_tags, $referenced_entity->getCacheTags());
        }
      }
    }

    return $cache_tags;
  }

  /**
   * Determina i cache contexts necessari per l'entità.
   */
  public function getCacheContexts(ContentEntityInterface $entity): array {
    $contexts = ['user.permissions'];

    // Context lingua — usa il context generico, non il valore specifico.
    if ($entity->isTranslatable()) {
      $contexts[] = 'languages:language_content';
    }

    return $contexts;
  }

  /**
   * Costruisce l'array di dati raw per un'entità.
   */
  protected function buildRawData(ContentEntityInterface $entity): array {
    // Usa l'entity key 'created' per evitare accoppiamento con NodeInterface.
    $created_key = $entity->getEntityType()->getKey('created');
    $created = ($created_key && $entity->hasField($created_key) && !$entity->get($created_key)->isEmpty())
      ? $this->formatDate((int) $entity->get($created_key)->value)
      : NULL;
    $changed = ($entity instanceof EntityChangedInterface)
      ? $this->formatDate($entity->getChangedTime())
      : NULL;

    $data = [
      'id' => $entity->id(),
      'uuid' => $entity->uuid(),
      'bundle' => $entity->bundle(),
      'entity_type' => $entity->getEntityTypeId(),
      'created' => $created,
      'changed' => $changed,
    ];

    // Processa solo i campi configurabili (field_*) e campi base selezionati.
    // I campi base dell'entità (id, uuid, bundle, created, changed) sono già
    // gestiti sopra. Questo evita il caricamento di entità non necessarie
    // (es. uid carica l'utente tramite entity_reference).
    foreach ($entity->getFields() as $field_name => $field) {
      if (!str_starts_with($field_name, 'field_') && $field_name !== 'body') {
        continue;
      }

      if (str_starts_with($field_name, 'field_')) {
        $clean_name = substr($field_name, 6);
      }
      else {
        $clean_name = $field_name;
      }

      $data[$clean_name] = $this->processField($field);
    }

    return $data;
  }

  /**
   * Processa un campo e restituisce i suoi valori in formato raw.
   *
  * Per i campi a valore singolo, restituisce direttamente il valore scalare
  * (o l'array associativo del singolo item). Per i campi multivalore,
  * restituisce sempre un array indicizzato per delta (anche con un solo
  * elemento). Per i campi vuoti, restituisce [] se il campo è multivalore,
  * altrimenti NULL.
   *
   * Nota: i campi di tipo entity_reference_revisions (es. Paragraphs)
   * non sono attualmente gestiti e vengono processati con il fallback
   * generico (valore grezzo del campo).
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   Il campo da processare.
   *
   * @return mixed
   *   I valori del campo in formato raw. Valore singolo per campi
   *   monovalore, array indicizzato stabile per campi multivalore,
   *   [] per campi multivalore vuoti, NULL per campi monovalore vuoti.
   */
  protected function processField(FieldItemListInterface $field): mixed {
    $field_type = $field->getFieldDefinition()->getType();
    $is_multiple = $field->getFieldDefinition()->getFieldStorageDefinition()->isMultiple();

    // Pre-caricamento entità referenziate per evitare query N+1.
    // referencedEntities() usa loadMultiple() internamente.
    $referenced_map = [];
    if ($field instanceof EntityReferenceFieldItemListInterface) {
      foreach ($field->referencedEntities() as $ref_entity) {
        $referenced_map[$ref_entity->id()] = $ref_entity;
        // Raccoglie i cache tags delle entità referenziate durante il processing
        // per evitare un secondo ciclo in collectCacheTags().
        $this->buildCacheTags = Cache::mergeTags($this->buildCacheTags, $ref_entity->getCacheTags());
      }
    }

    $values = [];
    
    foreach ($field->getValue() as $delta => $value) {
      switch ($field_type) {
        case 'link':
          // Normalizza l'URI tramite Url::fromUri() che rifiuta schemi
          // pericolosi (javascript:, data:) lanciando InvalidArgumentException.
          try {
            $url_string = Url::fromUri($value['uri'])->toString();
          }
          catch (\InvalidArgumentException $e) {
            $url_string = NULL;
          }
          $values[$delta] = [
            'url' => $url_string,
            'title' => $value['title'],
          ];
          break;

        case 'entity_reference':
          $target_id = $value['target_id'] ?? NULL;
          $target_entity = $target_id ? ($referenced_map[$target_id] ?? NULL) : NULL;
          if ($target_entity) {
            try {
              $url = $target_entity->toUrl()->toString();
            }
            catch (\Exception $e) {
              $url = NULL;
            }
            $values[$delta] = [
              'id' => $target_entity->id(),
              'uuid' => $target_entity->uuid(),
              'label' => $target_entity->label(),
              'url' => $url,
              'entity_type' => $target_entity->getEntityTypeId(),
              'bundle' => $target_entity->bundle(),
            ];

            // Gestione specifica per i termini di tassonomia.
            if ($target_entity instanceof TermInterface) {
              $values[$delta]['description'] = $target_entity->getDescription();
              // Processa tutti i campi field_* di tipo image/file del termine.
              foreach ($target_entity->getFields() as $ref_field_name => $ref_field) {
                if (!str_starts_with($ref_field_name, 'field_')) {
                  continue;
                }
                $ref_field_type = $ref_field->getFieldDefinition()->getType();
                if (in_array($ref_field_type, ['image', 'file'], TRUE)) {
                  $clean_ref_name = substr($ref_field_name, 6);
                  $values[$delta][$clean_ref_name] = $this->processField($ref_field);
                }
              }
            }
          }
          break;

        case 'image':
          $target_id = $value['target_id'] ?? NULL;
          $file_entity = $target_id ? ($referenced_map[$target_id] ?? NULL) : NULL;
          if ($file_entity instanceof FileInterface) {
            $file_uri = $file_entity->getFileUri();
            $values[$delta] = [
              'url' => $this->fileUrlGenerator->generateAbsoluteString($file_uri),
              'alt' => $value['alt'] ?? '',
              'title' => $value['title'] ?? '',
              'width' => $value['width'] ?? NULL,
              'height' => $value['height'] ?? NULL,
              'fid' => $file_entity->id(),
              'filename' => $file_entity->getFilename(),
              'filemime' => $file_entity->getMimeType(),
            ];
          }
          break;

        case 'file':
          $target_id = $value['target_id'] ?? NULL;
          $file_entity = $target_id ? ($referenced_map[$target_id] ?? NULL) : NULL;
          if ($file_entity instanceof FileInterface) {
            $file_uri = $file_entity->getFileUri();
            $values[$delta] = [
              'url' => $this->fileUrlGenerator->generateAbsoluteString($file_uri),
              'fid' => $file_entity->id(),
              'filename' => $file_entity->getFilename(),
              'filemime' => $file_entity->getMimeType(),
              'filesize' => $file_entity->getSize(),
            ];
          }
          break;

        case 'geofield':
          $values[$delta] = [
            'lat' => $value['lat'],
            'lon' => $value['lon'],
          ];
          break;

        case 'datetime':
        case 'date':
          if (!empty($value['value'])) {
            $values[$delta] = $this->formatDate(strtotime($value['value']));
          }
          break;

        case 'text':
        case 'text_long':
        case 'text_with_summary':
        case 'string':
        case 'string_long':
          $values[$delta] = $value['value'] ?? NULL;
          break;

        case 'boolean':
          $values[$delta] = (bool) ($value['value'] ?? FALSE);
          break;

        case 'integer':
        case 'number_integer':
          $values[$delta] = (int) ($value['value'] ?? 0);
          break;

        case 'decimal':
        case 'float':
        case 'number_decimal':
        case 'number_float':
          $values[$delta] = (float) ($value['value'] ?? 0);
          break;

        default:
          if (isset($value['value'])) {
            $values[$delta] = $value['value'];
          }
          else {
            $values[$delta] = $value;
          }
      }
    }

    // Struttura stabile: [] per campi multivalore vuoti, NULL per monovalore.
    if (empty($values)) {
      return $is_multiple ? [] : NULL;
    }

    if ($is_multiple) {
      return array_values($values);
    }

    // Campo monovalore: restituisce sempre il singolo valore.
    return reset($values);
  }

  /**
   * Formatta una data nel formato ISO 8601.
   */
  protected function formatDate(?int $timestamp): ?string {
    if (!$timestamp) {
      return NULL;
    }
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone($this->getTimezone())
      ->format('Y-m-d\TH:i:sP');
  }

  /**
   * Restituisce il timezone corrente, cached per la request.
   *
   * @return \DateTimeZone
   *   L'oggetto timezone.
   */
  protected function getTimezone(): \DateTimeZone {
    if ($this->timezone === NULL) {
      $this->timezone = new \DateTimeZone(date_default_timezone_get());
    }
    return $this->timezone;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredEntities(): array {
    return $this->getSettings()['raw_entities'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheStatistics(?string $entity_type_filter = NULL, ?string $bundle_filter = NULL): array {
    // Cache delle statistiche per evitare calcoli costosi ad ogni richiesta.
    $cid = 'ildeposito_raw:statistics';
    if ($entity_type_filter !== NULL) {
      $cid .= ':' . $entity_type_filter;
    }
    if ($bundle_filter !== NULL) {
      $cid .= ':' . $bundle_filter;
    }

    $cached = $this->cache->get($cid);
    if ($cached) {
      return $cached->data;
    }

    $settings = $this->getSettings();
    $raw_entities = $settings['raw_entities'];
    $languages = $this->languageManager->getLanguages();
    $total_entities = 0;
    $bundles = [];
    $cache_tags = ['config:ildeposito_raw.settings'];

    foreach ($raw_entities as $raw_entity) {
      if (empty($raw_entity['entity_type']) || empty($raw_entity['bundles']) || empty($raw_entity['view_modes'])) {
        continue;
      }

      $entity_type = $raw_entity['entity_type'];
      if ($entity_type_filter !== NULL && $entity_type_filter !== $entity_type) {
        continue;
      }

      $entity_type_definition = $this->entityTypeManager->getDefinition($entity_type);
      $bundle_key = $entity_type_definition->getKey('bundle');
      $cache_tags[] = 'ildeposito_raw:entity:' . $entity_type;
      $storage = $this->entityTypeManager->getStorage($entity_type);

      foreach ($raw_entity['bundles'] as $bundle) {
        if ($bundle_filter !== NULL && $bundle_filter !== $bundle) {
          continue;
        }

        $count_query = $storage->getQuery()
          ->accessCheck(FALSE)
          ->count();

        if ($bundle_key) {
          $count_query->condition($bundle_key, $bundle);
        }

        $count = (int) $count_query->execute();
        $bundle_label = $entity_type . '--' . $bundle;

        if (!isset($bundles[$bundle_label])) {
          $bundles[$bundle_label] = ['total' => 0, 'cached' => 0];
        }

        $bundles[$bundle_label]['total'] += $count;
        $total_entities += $count;

        // Verifica le entry in cache a chunk per evitare memory exhaustion.
        $cached_count = 0;
        $offset = 0;
        $chunk_size = 500;

        while ($offset < $count) {
          $id_query = $storage->getQuery()
            ->accessCheck(FALSE)
            ->range($offset, $chunk_size);

          if ($bundle_key) {
            $id_query->condition($bundle_key, $bundle);
          }

          $chunk_ids = $id_query->execute();
          if (empty($chunk_ids)) {
            break;
          }

          $cids = [];
          foreach ($chunk_ids as $eid) {
            foreach ($languages as $langcode => $language) {
              $cids[] = "ildeposito_raw:{$entity_type}:{$eid}:{$langcode}";
            }
          }
          $cids_to_check = $cids;
          $this->cache->getMultiple($cids_to_check);
          $cached_count += count($cids) - count($cids_to_check);

          $offset += $chunk_size;
        }

        $bundles[$bundle_label]['cached'] += $cached_count;
      }
    }

    $result = [
      'total_entities' => $total_entities,
      'bundles' => $bundles,
      'cache_tags' => $cache_tags,
    ];

    // Cache per 5 minuti con tags di invalidazione appropriati.
    $this->cache->set($cid, $result, $this->time->getRequestTime() + 300, $cache_tags);

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function resetCachedSettings(): void {
    $this->cachedSettings = NULL;
  }

  /**
   * Invalida la cache per un'entità specifica.
   *
   * Invalida solo i cache tags dell'entità stessa. Drupal propaga
   * automaticamente l'invalidazione alle entry della cache bin dedicata
   * che includono questi tags.
   */
  public function invalidateCache(EntityInterface $entity): void {
    $this->cacheTagsInvalidator->invalidateTags($entity->getCacheTags());
  }

}