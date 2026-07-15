<?php

declare(strict_types=1);

namespace Drupal\ildeposito_redirects\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ildeposito_redirects\Service\Report404Log;

final class Report404AzzeraForm extends ConfirmFormBase {

  public function __construct(
    private readonly Report404Log $log,
  ) {}

  public function getFormId(): string {
    return 'ildeposito_redirects_report404_azzera_form';
  }

  public function getQuestion(): TranslatableMarkup|string {
    return $this->t('Azzerare il log dei 404?');
  }

  public function getDescription(): TranslatableMarkup|string {
    return $this->t('Il log verrà troncato: le occorrenze registrate finora non saranno più recuperabili. Operazione irreversibile.');
  }

  public function getConfirmText(): TranslatableMarkup|string {
    return $this->t('Azzera');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('ildeposito_redirects.report404');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->log->truncate();
    $this->messenger()->addStatus($this->t('Log 404 azzerato.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
