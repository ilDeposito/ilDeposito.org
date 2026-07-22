<?php

declare(strict_types=1);

namespace Drupal\ildeposito_build\Form;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ildeposito_build\Service\GitHubWorkflowClient;

final class BuildFrontendForm extends FormBase {

  // Ridichiarato qui (non solo ereditato da FormBase) perché __wakeup()
  // deve girare nello scope di questa classe per poter inizializzare la
  // proprietà readonly sotto — vedi drupal.org/node/3110266.
  use DependencySerializationTrait;

  private const WORKFLOWS = [
    'content' => [
      'stage' => 'build-frontend-content-stage.yml',
      'prod' => 'build-frontend-content-prod.yml',
      // In locale non esiste una build: si aggancia al workflow di stage.
      'local' => 'build-frontend-content-stage.yml',
    ],
    'full' => [
      'stage' => 'build-frontend-stage.yml',
      'prod' => 'build-frontend-prod.yml',
      'local' => 'build-frontend-stage.yml',
    ],
  ];

  public function __construct(
    protected readonly GitHubWorkflowClient $githubClient,
  ) {}

  private static function getEnvironment(): string {
    $env = (string) \Drupal::request()->server->get('ILDEPOSITO_ENV', '');
    return in_array($env, ['stage', 'prod', 'local'], TRUE) ? $env : '';
  }

  private static function getWorkflow(string $mode): string {
    return self::WORKFLOWS[$mode][self::getEnvironment()] ?? '';
  }

  public function getFormId(): string {
    return 'ildeposito_build_frontend_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    if (!$this->githubClient->isConfigured()) {
      $form['warning'] = [
        '#markup' => '<div class="messages messages--warning">'
          . $this->t('GitHub App non configurata. Verifica App ID, Installation ID in <code>settings.php</code> e il file <code>.pem</code> in <code>backend/private/</code>.')
          . '</div>',
      ];
      return $form;
    }

    $running = $this->githubClient->hasConflictingRunInProgress(self::getWorkflow('content'))
      || $this->githubClient->hasConflictingRunInProgress(self::getWorkflow('full'));

    $form['description'] = [
      '#markup' => '<p>' . $this->t(
        'I contenuti che modifichi in Drupal (canti, autori, eventi, traduzioni…) non sono immediatamente visibili sul sito pubblico. '
        . 'Scegli come rigenerare il sito: le modifiche saranno online in pochi minuti.'
      ) . '</p><p>' . $this->t(
        '<strong>Pubblica contenuti</strong> rigenera solo le pagine (più veloce, non tocca i PDF dei canti). '
        . '<strong>Pubblica contenuti + PDF</strong> rigenera anche i PDF scaricabili dei canti modificati (più lenta).'
      ) . '</p>',
    ];

    if ($running) {
      $form['running'] = [
        '#weight' => -5,
        '#markup' => '<div class="messages messages--warning">'
          . $this->t('Una pubblicazione è già in corso. Attendi che finisca prima di avviarne un\'altra — <a href=":url" target="_blank" rel="noopener">controlla lo stato su GitHub</a>.', [':url' => $this->githubClient->getRepoUrl() . '/actions'])
          . '</div>',
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['content'] = [
      '#type' => 'submit',
      '#name' => 'content',
      '#value' => $this->t('Pubblica contenuti'),
      '#button_type' => 'primary',
      '#disabled' => $running,
    ];
    $form['actions']['full'] = [
      '#type' => 'submit',
      '#name' => 'full',
      '#value' => $this->t('Pubblica contenuti + PDF'),
      '#disabled' => $running,
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $mode = (string) ($form_state->getTriggeringElement()['#name'] ?? 'full');
    $workflow = self::getWorkflow($mode);

    if (!$this->githubClient->triggerWorkflow($workflow)) {
      $this->messenger()->addError($this->t('Impossibile avviare la build. Verifica la configurazione GitHub App e riprova.'));
      return;
    }

    // L'inizio/fine build effettivi vengono loggati da ildeposito.sh (canale
    // ildeposito_build), non da qui: è l'unico punto comune a tutte le fonti
    // di trigger (pulsante, run manuale su GitHub, server/crontab).
    $this->messenger()->addStatus($this->t('Pubblicazione avviata — <a href=":url" target="_blank" rel="noopener">segui l\'avanzamento su GitHub</a>, oppure ricarica questa pagina tra qualche minuto.', [':url' => $this->githubClient->getRepoUrl() . '/actions']));
  }

}
