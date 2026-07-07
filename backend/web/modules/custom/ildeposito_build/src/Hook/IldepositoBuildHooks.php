<?php

declare(strict_types=1);

namespace Drupal\ildeposito_build\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;

final class IldepositoBuildHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $version = $this->resolveGitVersion();

    return [
      'ildeposito_git_version' => [
        'title' => $this->t('Versione applicativa (git)'),
        'value' => $version ?? $this->t('Non disponibile'),
        'severity' => $version !== NULL ? RequirementSeverity::Info : RequirementSeverity::Warning,
      ],
    ];
  }

  /**
   * Risolve il riferimento git della versione deployata.
   *
   * In stage/prod il container php monta solo backend/, senza .git (che
   * sta nella root del monorepo): il valore arriva da un file scritto dai
   * workflow di deploy subito dopo il checkout. In locale (DDEV) l'intero
   * repo è montato, quindi si può interrogare git direttamente.
   */
  private function resolveGitVersion(): ?string {
    $deployFile = DRUPAL_ROOT . '/../.deploy-version';
    if (is_readable($deployFile)) {
      $value = trim((string) file_get_contents($deployFile));
      return $value !== '' ? $value : NULL;
    }

    $repoRoot = DRUPAL_ROOT . '/../..';
    if (is_dir($repoRoot . '/.git')) {
      $output = trim((string) shell_exec('git -C ' . escapeshellarg($repoRoot) . ' describe --tags --always 2>/dev/null'));
      return $output !== '' ? $output : NULL;
    }

    return NULL;
  }

  #[Hook('toolbar')]
  public function toolbar(): array {
    $env = (string) $this->requestStack->getCurrentRequest()?->server->get('ILDEPOSITO_ENV', '');
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
