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

  #[Hook('toolbar')]
  public function toolbar(): array {
    $env = $_SERVER['ILDEPOSITO_ENV'] ?? '';
    if (!in_array($env, ['stage', 'prod'], TRUE)) {
      return [];
    }

    if (!$this->currentUser->hasPermission('trigger frontend build')) {
      return [];
    }

    return [
      'ildeposito_build' => [
        '#type' => 'toolbar_item',
        'tab' => [
          '#type' => 'link',
          '#title' => $this->t('Pubblica contenuti'),
          '#url' => Url::fromRoute('ildeposito_build.build_frontend'),
          '#attributes' => [
            'class' => ['toolbar-icon', 'toolbar-icon-ildeposito-build'],
          ],
        ],
        '#attached' => [
          'library' => ['ildeposito_build/toolbar'],
        ],
        '#weight' => -5,
        '#cache' => [
          'contexts' => ['user.permissions'],
        ],
      ],
    ];
  }

}
