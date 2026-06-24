<?php

declare(strict_types=1);

namespace Drupal\ildeposito_build\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

final class IldepositoBuildHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
  ) {}

  private function isServerEnvironment(): bool {
    $env = $_SERVER['ILDEPOSITO_ENV'] ?? '';
    return in_array($env, ['stage', 'prod'], TRUE);
  }

  #[Hook('toolbar')]
  public function toolbar(): array {
    $items = [];

    $items['ildeposito_build'] = [
      '#type' => 'toolbar_item',
      '#access' => $this->isServerEnvironment()
        && $this->currentUser->hasPermission('trigger frontend build'),
      'tab' => [
        '#type' => 'link',
        '#title' => $this->t('Pubblica contenuti'),
        '#url' => Url::fromRoute('ildeposito_build.build_frontend'),
        '#attributes' => [
          'class' => ['toolbar-icon', 'toolbar-icon-system-admin-reports'],
        ],
      ],
      '#weight' => -5,
      '#cache' => [
        'contexts' => ['user.permissions'],
      ],
    ];

    return $items;
  }

}
