<?php

namespace Drupal\ildeposito_raw\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form di conferma per l'eliminazione di una configurazione raw.
 */
class RawEntityDeleteForm extends ConfirmFormBase {

  /**
   * L'indice della configurazione da eliminare.
   */
  protected int $delta;

  /**
   * La configurazione corrente dell'entità da eliminare.
   *
   * @var array
   */
  protected array $rawEntity = [];

  /**
   * Costruttore.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
  ) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ildeposito_raw_delete_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $entity_type = $this->rawEntity['entity_type'] ?? '';
    $bundles = implode(', ', $this->rawEntity['bundles'] ?? []);
    return $this->t('Sei sicuro di voler eliminare la configurazione per @type (bundle: @bundles)?', [
      '@type' => $entity_type,
      '@bundles' => $bundles,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return new Url('ildeposito_raw.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $delta = NULL): array {
    $config = $this->configFactory->get('ildeposito_raw.settings');
    $raw_entities = $config->get('raw_entities') ?? [];

    if ($delta === NULL || !isset($raw_entities[$delta])) {
      $this->messenger()->addWarning($this->t('Configurazione non trovata.'));
      return $form;
    }

    $this->delta = $delta;
    $this->rawEntity = $raw_entities[$delta];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->getEditable('ildeposito_raw.settings');
    $raw_entities = $config->get('raw_entities') ?? [];

    if (isset($raw_entities[$this->delta])) {
      unset($raw_entities[$this->delta]);
      $config->set('raw_entities', array_values($raw_entities))->save();
      $this->messenger()->addStatus($this->t('Configurazione eliminata con successo.'));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
