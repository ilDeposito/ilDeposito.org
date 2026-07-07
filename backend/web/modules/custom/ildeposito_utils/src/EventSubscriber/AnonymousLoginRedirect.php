<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Forza il login per qualunque rotta del backend, tranne le API e i file.
 *
 * Il sito è headless: solo i redattori usano l'interfaccia Drupal, il
 * pubblico consuma i contenuti tramite Astro via JSON:API (/jsonapi) e le
 * rotte custom sotto /api/. Le rotte di file/immagini restano accessibili
 * agli anonimi perché Astro scarica media (immagini, audio, PDF)
 * direttamente da Drupal in build time con fetch anonimo (vedi
 * frontend/src/lib/api/drupal/assets.ts) — bloccarle romperebbe il build.
 *
 * Gira PRIMA di \Drupal\Core\Http\EventListener\RouterListener (priorità 32):
 * quel listener fa match + controllo permessi in un solo passo
 * (AccessAwareRouter::matchRequest) e lancia AccessDeniedHttpException senza
 * mai valorizzare l'attributo "_route" sulla request — a quel punto è troppo
 * tardi per intercettare e redirigere, il 403 è già deciso. Per questo qui
 * si controlla solo il path grezzo, non il nome della rotta risolta.
 */
final class AnonymousLoginRedirect implements EventSubscriberInterface {

  private const ALLOWED_PATH_PREFIXES = [
    '/jsonapi',
    '/api/',
    '/system/files',
    '/user/login',
    '/user/password',
    '/user/reset',
  ];

  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 40]];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    if (!\Drupal::currentUser()->isAnonymous()) {
      return;
    }

    $path = $event->getRequest()->getPathInfo();

    foreach (self::ALLOWED_PATH_PREFIXES as $prefix) {
      if (str_starts_with($path, $prefix)) {
        return;
      }
    }
    if (str_contains($path, '/files/styles/')) {
      return;
    }

    $event->setResponse(new RedirectResponse('/user/login?destination=/dashboard'));
  }

}
