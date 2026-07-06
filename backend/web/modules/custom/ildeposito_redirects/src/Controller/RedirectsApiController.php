<?php

declare(strict_types=1);

namespace Drupal\ildeposito_redirects\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\State\StateInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

// Estende ControllerBase (non una classe plain) per ereditare
// ContainerInjectionInterface + AutowireTrait: senza, il class_resolver
// risolve la rotta "_controller" con `new $class()` a zero argomenti, perché
// il match "\Drupal\...\RedirectsApiController" con lo slash iniziale in
// routing.yml non combacia mai col service ID (senza slash) — verificato con
// un 500 reale in DDEV. Stesso motivo per cui RedirectsForm funziona: eredita
// ContainerInjectionInterface da FormBase.
final class RedirectsApiController extends ControllerBase {

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  public function list(): JsonResponse {
    $redirects = $this->state->get('ildeposito_redirects.parsed', []);

    $response = new JsonResponse(['redirects' => $redirects]);
    $response->setPublic();
    $response->setMaxAge(300);

    return $response;
  }

}
