<?php

declare(strict_types=1);

namespace Drupal\ildeposito_build\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ildeposito_build\Service\GitHubWorkflowClient;

final class BuildFrontendForm extends FormBase {

  private const WORKFLOWS = [
    'stage' => 'build-frontend-stage.yml',
    'prod' => 'build-frontend-prod.yml',
    // In locale non esiste una build: si aggancia al workflow di stage.
    'local' => 'build-frontend-stage.yml',
  ];
  private const MAX_POLLS = 120;
  private const POLL_INTERVAL = 3;
  private const ESTIMATED_DURATION = 180;

  public function __construct(
    private readonly GitHubWorkflowClient $githubClient,
  ) {}

  private static function getEnvironment(): string {
    $env = (string) \Drupal::request()->server->get('ILDEPOSITO_ENV', '');
    return in_array($env, ['stage', 'prod', 'local'], TRUE) ? $env : '';
  }

  private static function getWorkflow(): string {
    return self::WORKFLOWS[self::getEnvironment()] ?? '';
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

    $form['description'] = [
      '#markup' => '<p>' . $this->t(
        'I contenuti che modifichi in Drupal (canti, autori, eventi, traduzioni…) non sono immediatamente visibili sul sito pubblico. '
        . 'Clicca il pulsante qui sotto per avviare la rigenerazione del sito: le modifiche saranno online in pochi minuti.'
      ) . '</p>',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Pubblica contenuti'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $batch = [
      'title' => $this->t('Pubblicazione contenuti in corso…'),
      'operations' => [
        [[static::class, 'processBuild'], [self::getWorkflow()]],
      ],
      'finished' => [static::class, 'buildFinished'],
      'progress_message' => '',
    ];

    batch_set($batch);
  }

  // @todo Drupal 12: refactoring con batch DI-aware per eliminare \Drupal:: statici.
  public static function processBuild(string $workflow, array &$context): void {
    /** @var \Drupal\ildeposito_build\Service\GitHubWorkflowClient $client */
    $client = \Drupal::service('ildeposito_build.github_workflow');

    if (!isset($context['sandbox']['step'])) {
      $result = $client->triggerWorkflow($workflow);
      if (!$result) {
        $context['results']['error'] = TRUE;
        $context['results']['error_message'] = new TranslatableMarkup('Impossibile avviare la build. Verifica la configurazione GitHub App e riprova.');
        $context['finished'] = 1;
        return;
      }

      \Drupal::logger('ildeposito_build')->info('Build frontend avviata: @workflow', ['@workflow' => $workflow]);
      $context['sandbox']['step'] = 'waiting';
      $context['sandbox']['trigger_time'] = time();
      $context['sandbox']['polls'] = 0;
      $context['message'] = new TranslatableMarkup('Build avviata, in attesa di GitHub…');
      $context['finished'] = 0.05;
      return;
    }

    sleep(self::POLL_INTERVAL);
    $context['sandbox']['polls']++;

    if ($context['sandbox']['polls'] >= self::MAX_POLLS) {
      $context['results']['error'] = TRUE;
      $context['results']['error_message'] = new TranslatableMarkup('Timeout: la build non è terminata entro il tempo previsto.');
      \Drupal::logger('ildeposito_build')->error('Build frontend in timeout dopo @polls tentativi.', ['@polls' => $context['sandbox']['polls']]);
      $context['finished'] = 1;
      return;
    }

    if ($context['sandbox']['step'] === 'waiting') {
      $run = $client->findWorkflowRun($workflow, $context['sandbox']['trigger_time']);

      if ($run) {
        $context['sandbox']['step'] = 'polling';
        $context['sandbox']['run_id'] = $run['id'];
        $context['message'] = new TranslatableMarkup('Build in esecuzione…');
        $context['finished'] = 0.2;
      }
      else {
        $context['message'] = new TranslatableMarkup('In attesa di avvio…');
        $context['finished'] = 0.1;
      }
      return;
    }

    if ($context['sandbox']['step'] === 'polling') {
      $run = $client->getWorkflowRun($context['sandbox']['run_id']);

      if (!$run) {
        $context['results']['error'] = TRUE;
        $context['results']['error_message'] = new TranslatableMarkup('Errore durante il monitoraggio della build.');
        $context['finished'] = 1;
        return;
      }

      if ($run['status'] === 'completed') {
        $context['results']['conclusion'] = $run['conclusion'];
        $context['finished'] = 1;
        return;
      }

      $elapsed = time() - $context['sandbox']['trigger_time'];
      $progress = min(0.9, 0.2 + ($elapsed / self::ESTIMATED_DURATION) * 0.7);
      $context['finished'] = $progress;

      $minutes = intdiv($elapsed, 60);
      $seconds = $elapsed % 60;
      $context['message'] = $minutes > 0
        ? new TranslatableMarkup('Build in esecuzione… @min min @sec sec', ['@min' => $minutes, '@sec' => $seconds])
        : new TranslatableMarkup('Build in esecuzione… @sec secondi', ['@sec' => $seconds]);
    }
  }

  public static function buildFinished(bool $success, array $results, array $operations): void {
    if (!$success || !empty($results['error'])) {
      $message = $results['error_message'] ?? new TranslatableMarkup('Errore durante la pubblicazione.');
      \Drupal::messenger()->addError($message);
      return;
    }

    $conclusion = $results['conclusion'] ?? 'unknown';

    match ($conclusion) {
      'success' => self::logAndMessage('status', new TranslatableMarkup('Contenuti pubblicati con successo!')),
      'failure' => self::logAndMessage('error', new TranslatableMarkup('La build è fallita. Controlla i log su GitHub.')),
      'cancelled' => self::logAndMessage('warning', new TranslatableMarkup('La build è stata annullata.')),
      default => self::logAndMessage('warning', new TranslatableMarkup('La build è terminata con esito: @conclusion', ['@conclusion' => $conclusion])),
    };
  }

  private static function logAndMessage(string $messengerLevel, TranslatableMarkup $message): void {
    $logLevel = match ($messengerLevel) {
      'error' => 'error',
      'warning' => 'warning',
      default => 'info',
    };
    \Drupal::logger('ildeposito_build')->log($logLevel, (string) $message);
    match ($messengerLevel) {
      'error' => \Drupal::messenger()->addError($message),
      'warning' => \Drupal::messenger()->addWarning($message),
      default => \Drupal::messenger()->addStatus($message),
    };
  }

}
