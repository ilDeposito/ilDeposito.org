<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Aggiunge in OR un permesso dedicato ai report di stato e aggiornamenti.
 *
 * Nel core queste rotte richiedono 'administer site configuration', che
 * sbloccherebbe anche tutte le pagine admin/config/*. Il ruolo "god"
 * (redattore con privilegi estesi) deve poter consultare status report e
 * available updates senza ottenere accesso alla configurazione del sito.
 */
final class ReportsAccessRouteSubscriber extends RouteSubscriberBase {

  private const ROUTE_NAMES = [
    'system.status',
    'update.status',
    'update.manual_status',
  ];

  protected function alterRoutes(RouteCollection $collection): void {
    foreach (self::ROUTE_NAMES as $name) {
      $route = $collection->get($name);
      if ($route === NULL) {
        continue;
      }
      $route->setRequirement(
        '_permission',
        $route->getRequirement('_permission') . '+view status and update reports'
      );
    }
  }

}
