<?php

namespace Drupal\ildeposito_raw\Hook;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\ildeposito_raw\RawEntityManagerInterface;

/**
 * Hook implementations per il modulo ildeposito_raw.
 */
class IldepositoRawHooks {

  use StringTranslationTrait;

  /**
   * Il canale logger del modulo.
   */
  protected LoggerChannelInterface $logger;

  /**
   * Costruttore.
   */
  public function __construct(
    protected readonly RawEntityManagerInterface $rawManager,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly ThemeManagerInterface $themeManager,
    protected readonly StateInterface $state,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ildeposito_raw');
  }

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): string {
    if ($route_name === 'help.page.ildeposito_raw') {
      return '<p>' . (string) $this->t('Il modulo ilDeposito Raw fornisce dati grezzi delle entità per i template Twig, ottimizzato per Drupal 11. Permette di configurare quali tipi di entità e view mode devono essere elaborati in modalità raw, con caching nella cache bin dedicata.') . '</p>';
    }
    return '';
  }

  /**
   * Implements hook_entity_view_alter().
   *
   * Priorità negativa per eseguire dopo tutte le altre alterazioni,
   * sostituendo il build array con i dati raw.
   */
  #[Hook('entity_view_alter', order: Order::Last)]
  public function entityViewAlter(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display): void {
    if (!$this->rawManager->shouldProcessRaw($entity, $display->getMode())) {
      return;
    }

    // Ottieni i dati raw.
    $data = $this->rawManager->getRawData($entity);

    // Permetti ai moduli e al tema di alterare i dati raw.
    $this->moduleHandler->alter('ildeposito_raw_data', $data, $entity);
    $this->themeManager->alter('ildeposito_raw_data', $data, $entity);

    // Usa i cache tags già computati da getRawData (include tags custom
    // e config, coerenti sia su cache hit che cache miss).
    $cache_tags = $this->rawManager->getLastCacheTags();
    $cache_contexts = $this->rawManager->getCacheContexts($entity);
    $max_age = $this->rawManager->getCacheMaxAge();

    // Svuota il build array e aggiungi solo i dati raw.
    $view_mode = $display->getMode();
    $build = [
      '#theme' => 'ildeposito_raw',
      '#entity' => $entity,
      '#entity_type' => $entity->getEntityTypeId(),
      '#data' => $data,
      '#title' => $entity->label(),
      '#view_mode' => $view_mode,
      '#cache' => [
        'tags' => $cache_tags,
        'contexts' => $cache_contexts,
        'max-age' => $max_age,
      ],
    ];

    // Aggiungi la chiave #node se l'entità è un nodo.
    if ($entity->getEntityTypeId() === 'node') {
      $build['#node'] = $entity;
    }
  }

  /**
   * Implements hook_theme_suggestions_ildeposito_raw_alter().
   */
  #[Hook('theme_suggestions_ildeposito_raw_alter')]
  public function themeSuggestionsIldepositoRawAlter(array &$suggestions, array $variables): void {
    // Debug condizionato solo in ambiente di sviluppo.
    if ($this->state->get('ildeposito_raw.debug_mode', FALSE)) {
      $this->logger->notice('Theme suggestions variables: @vars', [
        '@vars' => implode(', ', array_keys($variables)),
      ]);
    }

    if (!empty($variables['entity']) && $variables['entity'] instanceof EntityInterface) {
      $entity = $variables['entity'];
      $bundle = $entity->bundle();
      $view_mode = $variables['view_mode'] ?? NULL;

      $suggestions[] = 'ildeposito_raw';
      if ($bundle) {
        $suggestions[] = 'ildeposito_raw__' . $bundle;
      }
      if ($bundle && $view_mode) {
        $suggestions[] = 'ildeposito_raw__' . $bundle . '__' . $view_mode;
      }
    }
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'ildeposito_raw' => [
        'variables' => [
          'entity' => NULL,
          'entity_type' => NULL,
          'data' => [],
          'title' => NULL,
          'view_mode' => NULL,
          'node' => NULL,
          'attributes' => [],
        ],
      ],
    ];
  }

  /**
   * Implements hook_entity_update().
   *
   * Safety net: Drupal core invalida già i cache tags delle entità durante
   * il save, ma questo hook garantisce l'invalidazione nel caso di storage
   * custom o edge case non coperti dal core.
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    if ($this->rawManager->isEntityTypeConfigured($entity->getEntityTypeId())) {
      $this->rawManager->invalidateCache($entity);
    }
  }

  /**
   * Implements hook_entity_delete().
   *
   * Safety net: come entityUpdate(), funge da garanzia aggiuntiva
   * per l'invalidazione dei dati raw in cache alla cancellazione dell'entità.
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    if ($this->rawManager->isEntityTypeConfigured($entity->getEntityTypeId())) {
      $this->rawManager->invalidateCache($entity);
    }
  }

}
