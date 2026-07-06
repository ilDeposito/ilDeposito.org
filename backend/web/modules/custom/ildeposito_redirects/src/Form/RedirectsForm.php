<?php

declare(strict_types=1);

namespace Drupal\ildeposito_redirects\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;

final class RedirectsForm extends FormBase {

  private const STATE_RAW = 'ildeposito_redirects.raw';
  private const STATE_PARSED = 'ildeposito_redirects.parsed';
  private const ALLOWED_HOST = 'ildeposito.org';

  // Whitelist volutamente stretta: questi valori finiscono in un blocco
  // `location`/`return` nginx generato a build time (vedi
  // frontend/scripts/generate-redirects.mjs). Nessun carattere che possa
  // alterare la sintassi del blocco (';', '{', '}', spazi, a capo).
  private const PATH_PATTERN = '#^/[A-Za-z0-9\-_./]*$#';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  public function getFormId(): string {
    return 'ildeposito_redirects_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['help'] = [
      '#markup' => '<p>' . $this->t(
        'Un redirect per riga, nel formato <code>/vecchio-path|/nuovo-path</code>. '
        . 'Righe vuote o che iniziano con <code>#</code> sono ignorate. '
        . 'Il target può essere un path relativo (es. <code>/canti/bella-ciao</code>) '
        . 'oppure un URL assoluto su @host.',
        ['@host' => self::ALLOWED_HOST],
      ) . '</p>',
    ];

    $form['raw'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Redirect'),
      '#rows' => 20,
      '#default_value' => (string) $this->state->get(self::STATE_RAW, ''),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Salva'),
      '#button_type' => 'primary',
    ];

    return $form;
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

      if (!preg_match(self::PATH_PATTERN, $from)) {
        $errors[] = (string) $this->t('Riga @n: il path di origine deve iniziare con "/" e contenere solo lettere, numeri, "-", "_", "." e "/".', ['@n' => $lineNumber]);
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

    $this->messenger()->addStatus($this->t('Redirect salvati. Le modifiche saranno visibili alla prossima pubblicazione contenuti.'));
  }

}
