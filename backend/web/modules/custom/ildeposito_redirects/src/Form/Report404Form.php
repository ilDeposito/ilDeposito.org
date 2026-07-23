<?php

declare(strict_types=1);

namespace Drupal\ildeposito_redirects\Form;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\ildeposito_redirects\Service\Report404Log;

final class Report404Form extends FormBase {

  // Ridichiarato qui (non solo ereditato da FormBase) perché __wakeup()
  // deve girare nello scope di questa classe per poter inizializzare le
  // proprietà readonly sotto — vedi drupal.org/node/3110266.
  use DependencySerializationTrait;

  private const PER_PAGE = 25;

  // Stessa collection letta da Report404EliminaSelezionatiForm.
  private const TEMPSTORE_COLLECTION = 'ildeposito_redirects';
  private const TEMPSTORE_KEY = 'report404_selezionati';

  public function __construct(
    protected readonly Report404Log $log,
    protected readonly PagerManagerInterface $pagerManager,
    protected readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  public function getFormId(): string {
    return 'ildeposito_redirects_report404_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $counts = $this->log->readCounts();

    if (!$counts) {
      $form['empty'] = [
        '#markup' => '<p>' . $this->t('Nessun 404 registrato dall\'ultimo azzeramento.') . '</p>',
      ];
      return $form;
    }

    // Filtro sostring (case-insensitive) sulle URI, via query string così
    // resta attivo attraverso i link del pager.
    $cerca = trim((string) $this->getRequest()->query->get('cerca', ''));
    if ($cerca !== '') {
      $counts = array_filter(
        $counts,
        static fn (string $uri): bool => stripos($uri, $cerca) !== FALSE,
        ARRAY_FILTER_USE_KEY,
      );
    }

    $totale = array_sum($counts);

    $pager = $this->pagerManager->createPager(count($counts), self::PER_PAGE);
    $page = $pager->getCurrentPage();
    $pageCounts = array_slice($counts, $page * self::PER_PAGE, self::PER_PAGE, TRUE);

    $options = [];
    foreach ($pageCounts as $uri => $count) {
      $options[$this->encodeKey($uri)] = [
        'occorrenze' => $count,
        'url' => $uri,
      ];
    }

    $form['filtro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form--inline']],
    ];
    $form['filtro']['cerca'] = [
      '#type' => 'search',
      '#title' => $this->t('Cerca URL'),
      '#default_value' => $cerca,
      '#size' => 40,
    ];
    $form['filtro']['applica'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filtra'),
      '#submit' => ['::filterSubmit'],
      // Evita la validazione del tableselect (selezione obbligatoria).
      '#limit_validation_errors' => [['cerca']],
    ];
    if ($cerca !== '') {
      $form['filtro']['reset'] = [
        '#type' => 'link',
        '#title' => $this->t('Azzera filtro'),
        '#url' => Url::fromRoute('ildeposito_redirects.report404'),
        '#attributes' => ['class' => ['button']],
      ];
    }

    $summary = $cerca === ''
      ? $this->formatPlural(
        $totale,
        '1 occorrenza su @unique URL uniche dall\'ultimo azzeramento.',
        '@count occorrenze su @unique URL uniche dall\'ultimo azzeramento.',
        ['@unique' => count($counts)],
      )
      : $this->formatPlural(
        $totale,
        '1 occorrenza su @unique URL corrispondenti a "@cerca".',
        '@count occorrenze su @unique URL corrispondenti a "@cerca".',
        ['@unique' => count($counts), '@cerca' => $cerca],
      );

    $form['summary'] = [
      '#markup' => '<p>' . $summary . '</p>',
    ];

    $form['table'] = [
      '#type' => 'tableselect',
      '#header' => [
        'occorrenze' => $this->t('Occorrenze'),
        'url' => $this->t('URL'),
      ],
      '#options' => $options,
      '#empty' => $cerca === ''
        ? $this->t('Nessun 404 in questa pagina.')
        : $this->t('Nessun URL corrisponde a "@cerca".', ['@cerca' => $cerca]),
    ];

    $form['pager'] = ['#type' => 'pager'];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['delete_selected'] = [
      '#type' => 'submit',
      '#value' => $this->t('Elimina selezionati'),
      '#submit' => ['::deleteSelectedSubmit'],
      '#attributes' => ['class' => ['button--report404-elimina-selezionati']],
    ];
    $form['actions']['azzera'] = [
      '#type' => 'link',
      '#title' => $this->t('Azzera log 404'),
      '#url' => Url::fromRoute('ildeposito_redirects.report404_azzera'),
      '#attributes' => ['class' => ['button', 'button--report404-azzera']],
    ];

    $form['#attached']['library'][] = 'ildeposito_redirects/report404';

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_filter((array) $form_state->getValue('table'));
    if (!$selected) {
      $form_state->setErrorByName('table', $this->t('Seleziona almeno un URL da eliminare.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Nessuna azione di default: l'unico submit button ha un handler dedicato.
  }

  public function filterSubmit(array &$form, FormStateInterface $form_state): void {
    $cerca = trim((string) $form_state->getValue('cerca'));
    $form_state->setRedirect(
      'ildeposito_redirects.report404',
      [],
      $cerca === '' ? [] : ['query' => ['cerca' => $cerca]],
    );
  }

  public function deleteSelectedSubmit(array &$form, FormStateInterface $form_state): void {
    $selected = array_keys(array_filter((array) $form_state->getValue('table')));
    $uris = array_map($this->decodeKey(...), $selected);

    $this->tempStoreFactory
      ->get(self::TEMPSTORE_COLLECTION)
      ->set(self::TEMPSTORE_KEY, $uris);

    $form_state->setRedirectUrl(Url::fromRoute('ildeposito_redirects.report404_elimina_selezionati'));
  }

  private function encodeKey(string $uri): string {
    return rtrim(strtr(base64_encode($uri), '+/', '-_'), '=');
  }

  private function decodeKey(string $key): string {
    $base64 = strtr($key, '-_', '+/');
    $padded = str_pad($base64, strlen($base64) + (4 - strlen($base64) % 4) % 4, '=');
    return (string) base64_decode($padded);
  }

}
