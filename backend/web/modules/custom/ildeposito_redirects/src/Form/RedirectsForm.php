<?php

declare(strict_types=1);

namespace Drupal\ildeposito_redirects\Form;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ildeposito_build\Service\GitHubWorkflowClient;

final class RedirectsForm extends FormBase {

  // Ridichiarato qui (non solo ereditato da FormBase) perché __wakeup()
  // deve girare nello scope di questa classe per poter inizializzare le
  // proprietà readonly sotto — vedi drupal.org/node/3110266.
  use DependencySerializationTrait;

  private const STATE_RAW = 'ildeposito_redirects.raw';
  private const STATE_PARSED = 'ildeposito_redirects.parsed';
  private const ALLOWED_HOST = 'ildeposito.org';

  // Whitelist volutamente stretta: questi valori finiscono in un blocco
  // `location`/`return` nginx generato a build time (vedi
  // frontend/scripts/generate-redirects.mjs). Nessun carattere che possa
  // alterare la sintassi del blocco (';', '{', '}', spazi, a capo).
  private const PATH_PATTERN = '#^/[A-Za-z0-9\-_./]*$#';

  // Come PATH_PATTERN ma ammette anche "*": come segmento intero tra due "/"
  // (es. "/canti/*/pdf"), sempre un intero path-segment mai libero in mezzo a
  // caratteri letterali, oppure come ultimo carattere dell'intera stringa
  // (prefix "aperto", comportamento invariato rispetto a prima). Genera una
  // location nginx regex se compare un "*" di segmento, altrimenti exact/
  // prefix match come oggi — vedi generate-redirects.mjs::renderBlock().
  // Solo per $from: il target resta sempre fisso, "*" non è ammesso in $to
  // (nessuna cattura del suffisso o del segmento).
  private const PATH_PATTERN_FROM = '#^/(?:[A-Za-z0-9\-_.]*|\*)(?:/(?:[A-Za-z0-9\-_.]*|\*))*\*?$#';

  // Workflow GitHub che rigenera _redirects.conf e ricarica nginx (vedi
  // ildeposito.sh build-redirect). Solo prod: a differenza di
  // build-frontend-*, non esiste un equivalente stage/local.
  private const PUBLISH_WORKFLOW = 'build-redirect-prod.yml';

  public function __construct(
    protected readonly StateInterface $state,
    protected readonly GitHubWorkflowClient $githubClient,
  ) {}

  private static function getEnvironment(): string {
    $env = (string) \Drupal::request()->server->get('ILDEPOSITO_ENV', '');
    return in_array($env, ['stage', 'prod', 'local'], TRUE) ? $env : '';
  }

  public function getFormId(): string {
    return 'ildeposito_redirects_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $canPublish = self::getEnvironment() === 'prod';
    $running = $canPublish
      && $this->githubClient->isConfigured()
      && $this->githubClient->hasConflictingRunInProgress(self::PUBLISH_WORKFLOW);

    if ($canPublish) {
      $form['publish'] = $this->buildPublishSection($running);
    }

    $form['help'] = [
      '#weight' => -5,
      '#markup' => '<p>' . $this->t(
        'Un redirect per riga, nel formato <code>/vecchio-path|/nuovo-path</code>. '
        . 'Righe vuote o che iniziano con <code>#</code> sono ignorate. '
        . 'Il target può essere un path relativo (es. <code>/canti/bella-ciao</code>) '
        . 'oppure un URL assoluto su @host.',
        ['@host' => self::ALLOWED_HOST],
      ) . '</p><p>' . $this->t(
        'Il path di origine può terminare con <code>*</code> per un redirect a prefisso: '
        . '<code>/canti*</code> cattura anche <code>/cantiamo</code> e <code>/canti/qualsiasi-cosa</code>; '
        . '<code>/canti/*</code> cattura solo ciò che sta sotto <code>/canti/</code> (non <code>/canti</code> stesso). '
        . 'Il target resta sempre fisso: non è possibile riportare nel redirect la parte catturata dal <code>*</code>.'
      ) . '</p><p>' . $this->t(
        'Un <code>*</code> può comparire anche come segmento intero in mezzo al path (mai unito a lettere): '
        . '<code>/canti/*/pdf*</code> cattura <code>/canti/727/pdf/testo</code>, <code>/canti/1403/pdf/accordi</code> '
        . 'e qualunque altro ID sotto <code>/canti/.../pdf</code>. Senza lo <code>*</code> finale, '
        . '<code>/canti/*/pdf</code> cattura solo il path esatto (non ciò che viene dopo <code>/pdf</code>).'
      ) . '</p>',
    ];

    $form['raw'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Redirect'),
      '#rows' => 20,
      '#weight' => 5,
      '#default_value' => (string) $this->state->get(self::STATE_RAW, ''),
    ];

    $form['actions'] = ['#type' => 'actions', '#weight' => 0];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Salva'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['button--redirects-salva']],
    ];

    if ($canPublish && $this->githubClient->isConfigured()) {
      $form['actions']['publish'] = [
        '#type' => 'submit',
        '#value' => $this->t('Pubblica redirect'),
        '#button_type' => 'primary',
        // Bottone indipendente dal form "Salva": pubblica lo state già
        // salvato senza richiedere che la textarea sia (ancora) valida.
        '#limit_validation_errors' => [],
        '#validate' => [],
        '#submit' => ['::publishSubmit'],
        '#disabled' => $running,
      ];
    }

    $form['#attached']['library'][] = 'ildeposito_redirects/redirects_form';

    return $form;
  }

  private function buildPublishSection(bool $running): array {
    if (!$this->githubClient->isConfigured()) {
      return [
        '#type' => 'container',
        '#weight' => -10,
        'warning' => [
          '#markup' => '<div class="messages messages--warning">'
            . $this->t('GitHub App non configurata: impossibile pubblicare i redirect da qui. Verifica App ID, Installation ID in <code>settings.php</code> e il file <code>.pem</code> in <code>backend/private/</code>.')
            . '</div>',
        ],
      ];
    }

    $section = [
      '#type' => 'container',
      '#weight' => -10,
      'description' => [
        '#markup' => '<p>' . $this->t(
          'Il salvataggio qui sotto aggiorna solo lo state di Drupal: i redirect vanno online sul sito solo dopo averli pubblicati.'
        ) . '</p>',
      ],
    ];

    if ($running) {
      $section['warning'] = [
        '#markup' => '<div class="messages messages--warning">'
          . $this->t('Una pubblicazione è già in corso (redirect o contenuti: condividono lo stesso slot di build). Attendi che finisca prima di avviarne un\'altra — <a href=":url" target="_blank" rel="noopener">controlla lo stato su GitHub</a>.', [':url' => $this->githubClient->getRepoUrl() . '/actions'])
          . '</div>',
      ];
    }

    return $section;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $raw = (string) $form_state->getValue('raw');
    $errors = [];
    $parsed = [];

    foreach (preg_split('/\r\n|\r|\n/', $raw) as $index => $line) {
      $lineNumber = $index + 1;
      $trimmed = trim($line);
      if ($trimmed === '' || str_starts_with($trimmed, '#')) {
        continue;
      }

      $parts = explode('|', $trimmed);
      if (count($parts) !== 2) {
        $errors[] = (string) $this->t('Riga @n: formato non valido, atteso "/vecchio|/nuovo".', ['@n' => $lineNumber]);
        continue;
      }

      [$from, $to] = array_map('trim', $parts);

      if (!preg_match(self::PATH_PATTERN_FROM, $from)) {
        $errors[] = (string) $this->t('Riga @n: il path di origine deve iniziare con "/", contenere solo lettere, numeri, "-", "_", "." e "/", ed eventualmente terminare con "*".', ['@n' => $lineNumber]);
        continue;
      }

      if (!$this->isValidTarget($to)) {
        $errors[] = (string) $this->t('Riga @n: il target deve essere un path relativo (stesso alfabeto del path di origine) o un URL assoluto su @host.', ['@n' => $lineNumber, '@host' => self::ALLOWED_HOST]);
        continue;
      }

      $parsed[] = ['from' => $from, 'to' => $to];
    }

    if ($errors) {
      $form_state->setErrorByName('raw', implode(' ', $errors));
      return;
    }

    $form_state->set('parsed', $parsed);
  }

  /**
   * Anti open-redirect: il target è un path relativo (mai "//host", che il
   * browser risolve come URL protocol-relative verso un host esterno) oppure
   * un URL assoluto il cui host è esattamente ALLOWED_HOST o un suo
   * sottodominio. Seconda barriera indipendente in generate-redirects.mjs,
   * che non si fida di questa validazione essendo l'endpoint pubblico.
   */
  private function isValidTarget(string $to): bool {
    if (str_starts_with($to, '/') && !str_starts_with($to, '//')) {
      return (bool) preg_match(self::PATH_PATTERN, $to);
    }

    if (!str_starts_with($to, 'https://') && !str_starts_with($to, 'http://')) {
      return FALSE;
    }

    $host = parse_url($to, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
      return FALSE;
    }

    return $host === self::ALLOWED_HOST || str_ends_with($host, '.' . self::ALLOWED_HOST);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $raw = (string) $form_state->getValue('raw');
    $this->state->set(self::STATE_RAW, $raw);
    $this->state->set(self::STATE_PARSED, $form_state->get('parsed') ?? []);

    $this->messenger()->addStatus($this->t('Redirect salvati. Premi "Pubblica redirect" per renderli attivi sul sito.'));
  }

  public function publishSubmit(array &$form, FormStateInterface $form_state): void {
    if (!$this->githubClient->triggerWorkflow(self::PUBLISH_WORKFLOW)) {
      $this->messenger()->addError($this->t('Impossibile avviare la pubblicazione. Verifica la configurazione GitHub App e riprova.'));
      return;
    }

    // L'inizio/fine build effettivi vengono loggati da ildeposito.sh (canale
    // ildeposito_build), non da qui: vedi README di ildeposito_build.
    $this->messenger()->addStatus($this->t('Pubblicazione avviata — <a href=":url" target="_blank" rel="noopener">segui l\'avanzamento su GitHub</a>, oppure ricarica questa pagina tra qualche minuto.', [':url' => $this->githubClient->getRepoUrl() . '/actions']));
  }

}
