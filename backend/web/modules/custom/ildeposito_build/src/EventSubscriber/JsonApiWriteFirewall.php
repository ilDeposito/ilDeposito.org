<?php

declare(strict_types=1);

namespace Drupal\ildeposito_build\EventSubscriber;

use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\Routing\Routes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final class JsonApiWriteFirewall implements EventSubscriberInterface {

  private const ALLOWED_WRITES = [
    'POST' => ['node--contatti'],
  ];

  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 28]];
  }

  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();

    if (!$request->attributes->get(Routes::JSON_API_ROUTE_FLAG_KEY)) {
      return;
    }

    $method = $request->getMethod();
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
      return;
    }

    $resourceType = $request->attributes->get(Routes::RESOURCE_TYPE_KEY);
    $typeName = $resourceType instanceof ResourceType
      ? $resourceType->getTypeName()
      : (string) $resourceType;

    $allowedTypes = self::ALLOWED_WRITES[$method] ?? [];

    if (!in_array($typeName, $allowedTypes, true)) {
      throw new MethodNotAllowedHttpException(
        ['GET', 'HEAD', 'OPTIONS'],
        sprintf('JSON:API write operations are not allowed for %s %s.', $method, $typeName),
      );
    }
  }

}
