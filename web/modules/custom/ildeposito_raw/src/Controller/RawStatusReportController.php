<?php

namespace Drupal\ildeposito_raw\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ildeposito_raw\RawEntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller per il report di stato della cache ilDeposito Raw.
 */
class RawStatusReportController extends ControllerBase {

  /**
   * Costruttore.
   */
  public function __construct(
    protected readonly RawEntityManagerInterface $rawManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ildeposito_raw.manager'),
    );
  }

  /**
   * Genera il report di stato della cache.
   *
   * @return array
   *   Il render array del report.
   */
  public function report(): array {
    $stats = $this->rawManager->getCacheStatistics();

    $header = [
      $this->t('Bundle'),
      $this->t('Entità totali'),
      $this->t('Voci in cache'),
    ];

    $rows = [];
    foreach ($stats['bundles'] as $bundle => $data) {
      $rows[] = [$bundle, $data['total'], $data['cached']];
    }

    return [
      'summary' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Entità totali: @count', ['@count' => $stats['total_entities']]),
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#caption' => $this->t('Report stato cache ilDeposito Raw'),
        '#empty' => $this->t('Nessuna entità configurata.'),
      ],
      '#cache' => [
        'tags' => $stats['cache_tags'],
        'contexts' => ['user.permissions'],
        'max-age' => $this->rawManager->getCacheMaxAge(),
      ],
    ];
  }

}
