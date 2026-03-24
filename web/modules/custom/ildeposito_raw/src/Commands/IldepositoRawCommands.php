<?php

namespace Drupal\ildeposito_raw\Commands;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\ildeposito_raw\RawEntityManagerInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Comandi Drush per il modulo ildeposito_raw.
 */
class IldepositoRawCommands extends DrushCommands {

  /**
   * Costruttore.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly CacheBackendInterface $cache,
    protected readonly RawEntityManagerInterface $rawManager,
    protected readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {
    parent::__construct();
  }

  /**
   * Esegue il warming della cache per i dati raw del modulo ildeposito_raw.
   *
   * @param array $options
   *   Opzioni del comando.
   *
   * @option entity-type
   *   Tipo di entità specifico da elaborare (opzionale).
   * @option bundle
   *   Bundle specifico da elaborare (opzionale).
   * @option view-mode
   *   View mode specifico da elaborare (opzionale).
   * @option limit
   *   Numero massimo di entità da elaborare per tipo (default: 0 = nessun limite).
   * @option clear-cache
   *   Se impostato, cancella la cache bin prima del warming (default: true).
   *
   * @command ildeposito:raw-cache-warm
   * @aliases ircw
   * @usage ildeposito:raw-cache-warm
   *   Esegue il warming della cache per tutte le entità configurate.
   * @usage ildeposito:raw-cache-warm --entity-type=node --bundle=article
   *   Esegue il warming della cache solo per i nodi di tipo article.
   */
  public function warmCache(array $options = [
    'entity-type' => NULL,
    'bundle' => NULL,
    'view-mode' => NULL,
    'limit' => 0,
    'clear-cache' => TRUE,
  ]) {
    $raw_entities = $this->getFilteredConfig($options);

    if (empty($raw_entities)) {
      $this->logger()->warning('Nessuna entità configurata per l\'elaborazione raw.');
      return;
    }

    // Cancella la cache bin prima del warming.
    if (!empty($options['clear-cache'])) {
      if (empty($options['entity-type']) && empty($options['bundle'])) {
        $this->logger()->notice('Cancellazione della cache bin ildeposito_raw...');
        $this->cache->deleteAll();
        $this->logger()->notice('Cache bin ildeposito_raw cancellata.');
      }
      else {
        $this->logger()->notice('Filtri attivi: la cache non viene cancellata interamente. Le entry verranno sovrascritte durante il warming.');
      }
    }

    $this->logger()->notice('Avvio del warming della cache...');
    $total_processed = 0;

    foreach ($raw_entities as $raw_entity) {
      $entity_type = $raw_entity['entity_type'];
      $bundles = $raw_entity['bundles'];
      $view_modes = $raw_entity['view_modes'];

      foreach ($bundles as $bundle) {
        $this->logger()->notice(sprintf('Elaborazione %s di tipo %s...', $entity_type, $bundle));

        $query = $this->entityTypeManager->getStorage($entity_type)->getQuery()
          ->accessCheck(FALSE);

        $entity_type_definition = $this->entityTypeManager->getDefinition($entity_type);
        $bundle_key = $entity_type_definition->getKey('bundle');
        if ($bundle_key) {
          $query->condition($bundle_key, $bundle);
        }

        if (!empty($options['limit']) && $options['limit'] > 0) {
          $query->range(0, $options['limit']);
        }

        $entity_ids = $query->execute();

        if (empty($entity_ids)) {
          $this->logger()->notice(sprintf('Nessuna entità trovata per %s di tipo %s.', $entity_type, $bundle));
          continue;
        }

        $count = count($entity_ids);

        $this->logger()->notice(sprintf('Trovate %d entità di tipo %s:%s da elaborare.', $count, $entity_type, $bundle));

        $progress = new ProgressBar($this->output(), $count);
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $progress->start();

        // Chunking per evitare memory exhaustion.
        $chunks = array_chunk($entity_ids, 50);
        $storage = $this->entityTypeManager->getStorage($entity_type);

        foreach ($chunks as $chunk) {
          $entities = $storage->loadMultiple($chunk);

          foreach ($entities as $entity) {
            if (!$entity instanceof ContentEntityInterface) {
              $progress->advance();
              continue;
            }
            // Elabora tutte le traduzioni dell'entità.
            foreach ($entity->getTranslationLanguages() as $langcode => $language) {
              $translation = $entity->getTranslation($langcode);
              // Il CID è indipendente dal view_mode, quindi basta verificare
              // che almeno una view_mode sia configurata. getRawData() popola
              // la cache una sola volta per entità+lingua.
              $is_configured = FALSE;
              foreach ($view_modes as $view_mode) {
                if ($this->rawManager->shouldProcessRaw($translation, $view_mode)) {
                  $is_configured = TRUE;
                  break;
                }
              }
              if ($is_configured) {
                $this->rawManager->getRawData($translation);
                $total_processed++;
              }
            }
            $progress->advance();
          }

          // Libera la cache statica delle entità tra un chunk e l'altro.
          $storage->resetCache($chunk);
        }

        $progress->finish();
        $this->output()->writeln('');
      }
    }

    if ($total_processed > 0) {
      $this->logger()->success(sprintf('Warming completato per %d entità.', $total_processed));
    }
    else {
      $this->logger()->warning('Nessuna entità elaborata. Verifica la configurazione del modulo.');
    }
  }

  /**
   * Invalida i dati raw in cache per un tipo di entità specifico.
   *
   * Usa il tag custom 'ildeposito_raw:entity:{type}' per invalidare in bulk
   * tutte le entry della cache bin che appartengono al tipo indicato.
   *
   * @param string $entity_type
   *   Il tipo di entità (es. 'node', 'taxonomy_term').
   *
   * @command ildeposito:raw-cache-invalidate
   * @aliases irci
   * @usage ildeposito:raw-cache-invalidate node
   *   Invalida tutti i dati raw in cache per i nodi.
   */
  public function invalidateByType(string $entity_type): void {
    if (!$this->rawManager->isEntityTypeConfigured($entity_type)) {
      $this->logger()->warning("Il tipo di entità '{$entity_type}' non è configurato nel modulo ildeposito_raw.");
      return;
    }

    $this->cacheTagsInvalidator->invalidateTags([
      'ildeposito_raw:entity:' . $entity_type,
    ]);
    $this->logger()->success("Cache raw invalidata per il tipo: {$entity_type}");
  }

  /**
   * Visualizza le statistiche sulla cache del modulo ildeposito_raw.
   *
   * @param array $options
   *   Opzioni del comando.
   *
   * @option entity-type
   *   Tipo di entità specifico (opzionale).
   * @option bundle
   *   Bundle specifico (opzionale).
   *
   * @command ildeposito:raw-cache-stats
   * @aliases ircs
   * @usage ildeposito:raw-cache-stats
   *   Visualizza le statistiche sulla cache per tutte le entità configurate.
   * @usage ildeposito:raw-cache-stats --entity-type=node --bundle=article
   *   Visualizza le statistiche sulla cache solo per i nodi di tipo article.
   */
  public function cacheStats(array $options = [
    'entity-type' => NULL,
    'bundle' => NULL,
  ]) {
    $stats = $this->rawManager->getCacheStatistics(
      $options['entity-type'] ?? NULL,
      $options['bundle'] ?? NULL,
    );

    if (empty($stats['bundles'])) {
      $this->logger()->warning('Nessuna entità trovata con i filtri specificati.');
      return;
    }

    $output = [];
    $output[] = 'Entità totali: ' . $stats['total_entities'];
    $output[] = '';
    $output[] = 'Entità per bundle:';
    foreach ($stats['bundles'] as $bundle => $data) {
      $output[] = sprintf('  %s: %d (%d in cache)', $bundle, $data['total'], $data['cached']);
    }
    $this->output()->writeln(implode("\n", $output));
  }

  /**
   * Filtra la configurazione in base alle opzioni specificate.
   *
   * @param array $options
   *   Le opzioni di filtro (entity-type, bundle, view-mode).
   *
   * @return array
   *   Le configurazioni filtrate e validate.
   */
  protected function getFilteredConfig(array $options): array {
    // Legge la configurazione tramite il manager per non duplicare
    // la logica di accesso alla config e beneficiare del caching per-request.
    $raw_entities = $this->rawManager->getConfiguredEntities();
    $filtered = [];

    foreach ($raw_entities as $raw_entity) {
      if (empty($raw_entity['entity_type']) || empty($raw_entity['bundles']) || empty($raw_entity['view_modes'])) {
        continue;
      }

      if (!empty($options['entity-type']) && $options['entity-type'] !== $raw_entity['entity_type']) {
        continue;
      }

      $bundles = is_array($raw_entity['bundles']) ? $raw_entity['bundles'] : [];
      if (!empty($options['bundle'])) {
        $bundles = array_intersect($bundles, [$options['bundle']]);
        if (empty($bundles)) {
          continue;
        }
      }

      $view_modes = is_array($raw_entity['view_modes']) ? $raw_entity['view_modes'] : [];
      if (!empty($options['view-mode']) && !in_array($options['view-mode'], $view_modes)) {
        continue;
      }

      $filtered[] = [
        'entity_type' => $raw_entity['entity_type'],
        'bundles' => array_values($bundles),
        'view_modes' => $view_modes,
      ];
    }

    return $filtered;
  }

}
