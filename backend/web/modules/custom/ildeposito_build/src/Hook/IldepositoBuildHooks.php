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
    $info = $this->resolveGitInfo();

    return [
      'ildeposito_git_version' => [
        'title' => $this->t('Versione ilDeposito.org'),
        'value' => $info['ref'] ?? $this->t('Non disponibile'),
        'description' => $info['message'],
        'severity' => $info['ref'] !== NULL ? RequirementSeverity::Info : RequirementSeverity::Warning,
      ],
    ];
  }

  /**
   * Risolve il riferimento git (tag o SHA) e il messaggio dell'ultimo commit.
   *
   * In stage/prod il container php monta solo backend/, senza .git (che
   * sta nella root del monorepo): i valori arrivano da un file scritto dai
   * workflow di deploy subito dopo il checkout (prima riga: ref, seconda
   * riga: oggetto del commit). In locale (DDEV) l'intero repo è montato,
   * quindi si può interrogare git direttamente.
   *
   * @return array{ref: string|null, message: string|null}
   */
  private function resolveGitInfo(): array {
    $deployFile = DRUPAL_ROOT . '/../.deploy-version';
    if (is_readable($deployFile)) {
      $lines = explode("\n", trim((string) file_get_contents($deployFile)));
      return [
        'ref' => $lines[0] !== '' ? $lines[0] : NULL,
        'message' => $lines[1] ?? NULL,
      ];
    }

    $repoRoot = DRUPAL_ROOT . '/../..';
    if (is_dir($repoRoot . '/.git')) {
      $repo = escapeshellarg($repoRoot);
      $ref = trim((string) shell_exec("git -C $repo describe --tags --always 2>/dev/null"));
      $message = trim((string) shell_exec("git -C $repo log -1 --pretty=%s 2>/dev/null"));
      return [
        'ref' => $ref !== '' ? $ref : NULL,
        'message' => $message !== '' ? $message : NULL,
      ];
    }

    return ['ref' => NULL, 'message' => NULL];
  }

  #[Hook('toolbar')]
  public function toolbar(): array {
    $env = (string) $this->requestStack->getCurrentRequest()?->server->get('ILDEPOSITO_ENV', '');
    if (!in_array($env, ['stage', 'prod', 'local'], TRUE)) {
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
