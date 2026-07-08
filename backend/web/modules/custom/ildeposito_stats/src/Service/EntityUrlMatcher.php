<?php

declare(strict_types=1);

namespace Drupal\ildeposito_stats\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Risolve un path frontend all'entità Drupal corrispondente, riusando il
 * sistema di alias core (path_alias) invece di reimplementare il matching
 * per prefisso: lo slug frontend è sempre l'ultimo segmento dell'alias
 * generato da Pathauto (vedi frontend/src/lib/api/drupal/resolvers.ts,
 * extractSlug), e le pagine libere (bundle "pagina") usano l'alias intero —
 * risolvere via path_alias copre entrambi i casi senza distinzioni.
 */
final class EntityUrlMatcher {

  private const NODE_BUNDLES = ['canto', 'autore', 'evento', 'traduzione', 'pagina'];
  private const TAXONOMY_VOCABULARIES = ['lingue', 'localizzazioni', 'periodi', 'tags', 'tematiche'];

  /**
   * Cache di processo: match() viene chiamato fino a 4 volte per lo stesso
   * URL nello stesso run (delta/6h/24h/settimana si sovrappongono molto),
   * ogni volta con una query path_alias altrimenti ripetuta.
   *
   * @var array<string, \Drupal\Core\Entity\ContentEntityInterface|null>
   */
  private array $matchCache = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Risolve e aggrega righe {url, visite}: più URL che puntano alla stessa
   * entità (es. varianti con frammento normalizzate allo stesso alias)
   * sommano le rispettive visite in un'unica riga.
   *
   * @param array<int, array{url: string, visite: int}> $rows
   *
   * @return array<string, array{entity: \Drupal\Core\Entity\ContentEntityInterface, visite: int}>
   *   Chiave: "{entity_type_id}:{id}".
   */
  public function matchAndAggregate(array $rows): array {
    $matched = [];
    foreach ($rows as $row) {
      $entity = $this->match($row['url']);
      if (!$entity) {
        continue;
      }

      $key = $entity->getEntityTypeId() . ':' . $entity->id();
      $matched[$key]['entity'] ??= $entity;
      $matched[$key]['visite'] = ($matched[$key]['visite'] ?? 0) + $row['visite'];
    }

    return $matched;
  }

  /**
   * Carica un'entità dalla chiave prodotta da matchAndAggregate()
   * ("{entity_type_id}:{id}"), per ricaricare un'entità di un run
   * precedente non più presente nel batch corrente (es. per azzerarne gli
   * snapshot temporali quando non ha più visite recenti).
   */
  public function loadByKey(string $key): ?ContentEntityInterface {
    [$entityTypeId, $id] = explode(':', $key, 2) + [NULL, NULL];
    if ($entityTypeId === NULL || $id === NULL) {
      return NULL;
    }

    $entity = $this->entityTypeManager->getStorage($entityTypeId)->load($id);
    return $entity instanceof ContentEntityInterface ? $entity : NULL;
  }

  public function match(string $path): ?ContentEntityInterface {
    $normalized = $this->normalize($path);
    if (array_key_exists($normalized, $this->matchCache)) {
      return $this->matchCache[$normalized];
    }

    return $this->matchCache[$normalized] = $this->resolveMatch($normalized);
  }

  private function resolveMatch(string $normalized): ?ContentEntityInterface {
    $aliases = $this->entityTypeManager->getStorage('path_alias')
      ->loadByProperties(['alias' => $normalized]);
    $alias = reset($aliases);
    if ($alias === FALSE) {
      return NULL;
    }

    $internalPath = $alias->getPath();

    if (preg_match('#^/node/(\d+)$#', $internalPath, $matches) === 1) {
      $node = $this->entityTypeManager->getStorage('node')->load((int) $matches[1]);
      return $node instanceof NodeInterface && in_array($node->bundle(), self::NODE_BUNDLES, TRUE) ? $node : NULL;
    }

    if (preg_match('#^/taxonomy/term/(\d+)$#', $internalPath, $matches) === 1) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load((int) $matches[1]);
      return $term instanceof TermInterface && in_array($term->bundle(), self::TAXONOMY_VOCABULARIES, TRUE) ? $term : NULL;
    }

    return NULL;
  }

  /**
   * Rimuove il frammento (es. "#ricercaForm", trascinato in Umami dal
   * fullPath del browser) e lo slash finale, per matchare il formato
   * esatto salvato in path_alias.
   */
  private function normalize(string $path): string {
    $path = rtrim(explode('#', $path, 2)[0], '/');
    return $path === '' ? '/' : $path;
  }

}
