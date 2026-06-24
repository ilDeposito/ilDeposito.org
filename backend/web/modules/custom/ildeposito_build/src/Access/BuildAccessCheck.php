<?php

declare(strict_types=1);

namespace Drupal\ildeposito_build\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;

final class BuildAccessCheck {

  public function access(): AccessResultInterface {
    $env = $_SERVER['ILDEPOSITO_ENV'] ?? '';
    return AccessResult::allowedIf(in_array($env, ['stage', 'prod'], TRUE));
  }

}
