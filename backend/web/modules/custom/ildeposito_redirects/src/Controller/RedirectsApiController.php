<?php

declare(strict_types=1);

namespace Drupal\ildeposito_redirects\Controller;

use Drupal\Core\State\StateInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

final class RedirectsApiController {

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
