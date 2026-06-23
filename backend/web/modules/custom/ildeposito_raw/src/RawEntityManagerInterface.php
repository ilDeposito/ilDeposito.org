<?php

namespace Drupal\ildeposito_raw;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Interface per il servizio di gestione dati raw delle entità.
 */
interface RawEntityManagerInterface {

  /**
   * Restituisce il max-age dalla configurazione.
   *
   * @return int
   *   Il valore max-age per la cache.
   */
  public function getCacheMaxAge(): int;

  /**
   * Verifica se un tipo di entità è configurato per l'elaborazione raw.
   *
   * @param string $entity_type_id
   *   L'ID del tipo di entità.
   *
   * @return bool
   *   TRUE se il tipo di entità è configurato.
   */
  public function isEntityTypeConfigured(string $entity_type_id): bool;

  /**
   * Restituisce i cache tags computati nell'ultima chiamata a getRawData.
   *
   * @return array
   *   I cache tags.
   */
  public function getLastCacheTags(): array;

  /**
   * Verifica se l'entità deve essere elaborata in modalità raw.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   L'entità da verificare.
   * @param string $view_mode
   *   La view mode.
   *
   * @return bool
   *   TRUE se l'entità deve essere elaborata in modalità raw.
   */
  public function shouldProcessRaw(EntityInterface $entity, string $view_mode = 'default'): bool;

  /**
   * Genera i dati raw per un'entità, con caching nella cache bin dedicata.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   L'entità da elaborare.
   *
   * @return array
   *   I dati raw dell'entità.
   */
  public function getRawData(EntityInterface $entity): array;

  /**
   * Restituisce i dati raw e i relativi cache tags in modo atomico.
   *
   * Preferire questo metodo a getRawData() + getLastCacheTags() quando
   * il codice chiamante potrebbe invocare getRawData() su più entità.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   L'entità da elaborare.
   *
   * @return array
   *   Array con due chiavi:
   *   - 'data': i dati raw dell'entità.
   *   - 'tags': i cache tags associati.
   */
  public function getRawDataWithTags(EntityInterface $entity): array;

  /**
   * Determina i cache contexts necessari per l'entità.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   L'entità.
   *
   * @return array
   *   Array di cache contexts.
   */
  public function getCacheContexts(ContentEntityInterface $entity): array;

  /**
   * Restituisce le statistiche sulla cache per le entità configurate.
   *
   * @param string|null $entity_type_filter
   *   Tipo di entità specifico (opzionale).
   * @param string|null $bundle_filter
   *   Bundle specifico (opzionale).
   *
   * @return array
   *   Array con le statistiche:
   *   - total_entities: Numero totale di entità.
   *   - bundles: Array associativo con chiave "entity_type--bundle" e valore
   *     array con 'total' e 'cached'.
   *   - cache_tags: Array di cache tags rilevanti.
   */
  public function getCacheStatistics(?string $entity_type_filter = NULL, ?string $bundle_filter = NULL): array;

  /**
   * Resetta la configurazione cached per la request corrente.
   */
  public function resetCachedSettings(): void;

  /**
   * Invalida la cache per un'entità.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   L'entità la cui cache deve essere invalidata.
   */
  public function invalidateCache(EntityInterface $entity): void;

}
