<?php

declare(strict_types=1);

namespace Drupal\ildeposito_redirects\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\ildeposito_redirects\Service\Report404Log;

final class Report404EliminaSelezionatiForm extends ConfirmFormBase {

  // Stessa collection scritta da Report404Form::deleteSelectedSubmit().
  private const TEMPSTORE_COLLECTION = 'ildeposito_redirects';
  private const TEMPSTORE_KEY = 'report404_selezionati';

  public function __construct(
    private readonly Report404Log $log,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  public function getFormId(): string {
    return 'ildeposito_redirects_report404_elimina_selezionati_form';
  }

  public function getQuestion(): TranslatableMarkup|string {
    $uris = $this->getSelectedUris();
    return $this->formatPlural(
      count($uris),
      'Eliminare 1 URL selezionata dal log dei 404?',
      'Eliminare @count URL selezionate dal log dei 404?',
    );
  }

  public function getDescription(): TranslatableMarkup|string {
    return $this->t('Tutte le occorrenze delle URL elencate qui sotto verranno rimosse dal log. Operazione irreversibile.');
  }

  public function getConfirmText(): TranslatableMarkup|string {
    return $this->t('Elimina');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('ildeposito_redirects.report404');
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $uris = $this->getSelectedUris();

    if (!$uris) {
      $this->messenger()->addWarning($this->t('Nessuna selezione da eliminare: torna al report 404 e seleziona almeno un URL.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return $form;
    }

    $form = parent::buildForm($form, $form_state);

    $form['elenco'] = [
      '#theme' => 'item_list',
      '#items' => $uris,
      '#weight' => -1,
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uris = $this->getSelectedUris();
    $rimosse = $this->log->deleteUris($uris);
    $this->tempStoreFactory->get(self::TEMPSTORE_COLLECTION)->delete(self::TEMPSTORE_KEY);

    $this->messenger()->addStatus($this->formatPlural(
      count($uris),
      '1 URL eliminata dal log (@occorrenze occorrenze rimosse).',
      '@count URL eliminate dal log (@occorrenze occorrenze rimosse).',
      ['@occorrenze' => $rimosse],
    ));

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * @return string[]
   */
  private function getSelectedUris(): array {
    $uris = $this->tempStoreFactory->get(self::TEMPSTORE_COLLECTION)->get(self::TEMPSTORE_KEY);
    return is_array($uris) ? $uris : [];
  }

}
