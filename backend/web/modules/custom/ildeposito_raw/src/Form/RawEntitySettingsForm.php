<?php

namespace Drupal\ildeposito_raw\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form di configurazione per il modulo Il Deposito Raw.
 */
class RawEntitySettingsForm extends ConfigFormBase {

  /**
   * Costruttore.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityDisplayRepositoryInterface $entityDisplayRepository,
    protected EntityTypeBundleInfoInterface $bundleInfo,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('entity_display.repository'),
      $container->get('entity_type.bundle.info')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ildeposito_raw_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ildeposito_raw.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ildeposito_raw.settings');
    $raw_entities = $config->get('raw_entities') ?? [];

    $form['raw_entities'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Tipo entità'),
        $this->t('Bundle'),
        $this->t('View mode'),
        $this->t('Operazioni'),
      ],
      '#empty' => $this->t('Nessuna configurazione presente.'),
    ];

    // Aggiungi righe esistenti
    foreach ($raw_entities as $delta => $raw_entity) {
      $form['raw_entities'][$delta] = [
        'entity_type' => [
          '#markup' => $raw_entity['entity_type'],
        ],
        'bundles' => [
          '#markup' => implode(', ', $raw_entity['bundles']),
        ],
        'view_modes' => [
          '#markup' => implode(', ', $raw_entity['view_modes']),
        ],
        'operations' => [
          '#type' => 'operations',
          '#links' => [
            'delete' => [
              'title' => $this->t('Elimina'),
              'url' => Url::fromRoute('ildeposito_raw.delete_config', [
                'delta' => $delta,
              ]),
            ],
          ],
        ],
      ];
    }

    // Form per aggiungere nuova configurazione
    $entity_types = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if ($entity_type->entityClassImplements('\Drupal\Core\Entity\ContentEntityInterface')) {
        $entity_types[$entity_type_id] = $entity_type->getLabel();
      }
    }

    $form['add'] = [
      '#type' => 'details',
      '#title' => $this->t('Aggiungi configurazione'),
      '#open' => TRUE,
    ];

    $form['add']['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Tipo entità'),
      '#options' => $entity_types,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateEntityTypeFields',
        'wrapper' => 'entity-type-dependent-wrapper',
      ],
    ];

    $form['add']['entity_type_dependent'] = [
      '#type' => 'container',
      '#prefix' => '<div id="entity-type-dependent-wrapper">',
      '#suffix' => '</div>',
      '#tree' => TRUE,
    ];

    $form['add']['entity_type_dependent']['bundles'] = [
      '#type' => 'select',
      '#title' => $this->t('Bundle'),
      '#options' => $this->getBundleOptions($form_state->getValue('entity_type')),
      '#multiple' => TRUE,
      '#required' => TRUE,
      // Reset del valore quando cambia l'entity type via AJAX per evitare
      // che selezioni del tipo precedente sopravvivano al cambio.
      '#value' => [],
    ];

    $form['add']['entity_type_dependent']['view_modes'] = [
      '#type' => 'select',
      '#title' => $this->t('View mode'),
      '#options' => $this->getViewModeOptions($form_state->getValue('entity_type')),
      '#multiple' => TRUE,
      '#required' => TRUE,
      // Reset del valore quando cambia l'entity type via AJAX.
      '#value' => [],
    ];

    $form['add']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Aggiungi configurazione'),
      '#submit' => ['::submitForm'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Ajax callback per aggiornare i campi dipendenti dal tipo di entità.
   */
  public function updateEntityTypeFields(array &$form, FormStateInterface $form_state): array {
    return $form['add']['entity_type_dependent'];
  }

  /**
   * Ottiene le opzioni dei bundle per un tipo di entità.
   *
   * @param string|null $entity_type_id
   *   L'ID del tipo di entità.
   *
   * @return array
   *   Le opzioni dei bundle.
   */
  protected function getBundleOptions(?string $entity_type_id): array {
    $options = [];

    if ($entity_type_id) {
      $bundles = $this->bundleInfo->getBundleInfo($entity_type_id);

      foreach ($bundles as $bundle => $info) {
        $options[$bundle] = $info['label'];
      }
    }

    return $options;
  }

  /**
   * Ottiene le opzioni delle view mode per un tipo di entità.
   *
   * @param string|null $entity_type_id
   *   L'ID del tipo di entità.
   *
   * @return array
   *   Le opzioni delle view mode.
   */
  protected function getViewModeOptions(?string $entity_type_id): array {
    $options = ['default' => $this->t('Default')];

    if ($entity_type_id) {
      $view_modes = $this->entityDisplayRepository->getViewModes($entity_type_id);
      
      foreach ($view_modes as $view_mode_id => $view_mode_info) {
        $options[$view_mode_id] = $view_mode_info['label'];
      }
    }
    
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $entity_type = $form_state->getValue('entity_type');
    if (!$entity_type) {
      return;
    }

    $bundles = $form_state->getValue(['entity_type_dependent', 'bundles']);
    $view_modes = $form_state->getValue(['entity_type_dependent', 'view_modes']);

    if (empty($bundles)) {
      $form_state->setErrorByName('entity_type_dependent][bundles', $this->t('Selezionare almeno un bundle.'));
      return;
    }

    if (empty($view_modes)) {
      $form_state->setErrorByName('entity_type_dependent][view_modes', $this->t('Selezionare almeno una view mode.'));
      return;
    }

    // Verifica duplicati nella configurazione esistente.
    $config = $this->config('ildeposito_raw.settings');
    $raw_entities = $config->get('raw_entities') ?? [];

    foreach ($raw_entities as $raw_entity) {
      if ($raw_entity['entity_type'] === $entity_type) {
        $overlapping_bundles = array_intersect($bundles, $raw_entity['bundles']);
        $overlapping_view_modes = array_intersect($view_modes, $raw_entity['view_modes']);
        if (!empty($overlapping_bundles) && !empty($overlapping_view_modes)) {
          $form_state->setErrorByName('entity_type_dependent][bundles', $this->t(
            'Esiste già una configurazione per @type con bundle @bundles e view mode @modes.',
            [
              '@type' => $entity_type,
              '@bundles' => implode(', ', $overlapping_bundles),
              '@modes' => implode(', ', $overlapping_view_modes),
            ]
          ));
          return;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('ildeposito_raw.settings');
    $raw_entities = $config->get('raw_entities') ?? [];

    if ($entity_type = $form_state->getValue('entity_type')) {
      // Verifica server-side che il tipo esista nel sistema, come protezione
      // contro richieste con dati manipolati (la select garantisce ciò in
      // condizioni normali, ma la validazione server-side è necessaria).
      if (!$this->entityTypeManager->hasDefinition($entity_type)) {
        $this->messenger()->addError($this->t('Tipo di entità non valido.'));
        return;
      }

      $raw_entities[] = [
        'entity_type' => $entity_type,
        'bundles' => array_values(array_filter($form_state->getValue(['entity_type_dependent', 'bundles']))),
        'view_modes' => array_values(array_filter($form_state->getValue(['entity_type_dependent', 'view_modes']))),
      ];

      $config->set('raw_entities', $raw_entities)->save();
    }

    parent::submitForm($form, $form_state);
  }
}