<?php

declare(strict_types=1);

namespace Drupal\ildeposito_build\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\Cache;
use Symfony\Component\HttpFoundation\RequestStack;

final class BuildAccessCheck {

  public function __construct(
    private readonly RequestStack $requestStack,
  ) {}

  public function access(): AccessResultInterface {
    $env = (string) $this->requestStack->getCurrentRequest()?->server->get('ILDEPOSITO_ENV', '');
    return AccessResult::allowedIf(in_array($env, ['stage', 'prod'], TRUE))
      ->setCacheMaxAge(Cache::PERMANENT);
  }

}
