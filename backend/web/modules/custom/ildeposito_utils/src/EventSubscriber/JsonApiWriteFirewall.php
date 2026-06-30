<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\EventSubscriber;

use Drupal\jsonapi\Routing\Routes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Blocca tutti i write JSON:API per gli utenti anonimi.
 *
 * Per gli utenti autenticati (es. ruolo "api") il controllo è delegato
 * all'access handler dell'entity target. Questo permette di gestire
 * le autorizzazioni per entity type via permessi Drupal standard,
 * senza dover aggiornare questo firewall ad ogni nuova entity.
 *
 * L'autenticazione Drupal avviene tramite HTTP Basic Auth passato da Astro
 * server-side, sopra al basic auth infrastrutturale di Caddy.
 */
final class JsonApiWriteFirewall implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 28]];
  }

  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();

    if (!$request->attributes->get(Routes::JSON_API_ROUTE_FLAG_KEY)) {
      return;
    }

    if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], TRUE)) {
      return;
    }

    if (\Drupal::currentUser()->isAnonymous()) {
      $event->setResponse(new JsonResponse(
        ['errors' => [['title' => 'Unauthorized', 'detail' => 'JSON:API write operations require authentication.']]],
        Response::HTTP_UNAUTHORIZED,
      ));
    }
  }

}
