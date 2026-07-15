<?php

declare(strict_types=1);

namespace Drupal\ildeposito_redirects\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\ildeposito_redirects\Service\Report404Log;

// Estende ControllerBase (non una classe plain) per ereditare
// ContainerInjectionInterface + AutowireTrait: senza, il class_resolver
// risolve la rotta "_controller" con `new $class()` a zero argomenti, perché
// il match "\Drupal\...\ClassName" con lo slash iniziale in routing.yml non
// combacia mai col service ID (senza slash) — vedi RedirectsApiController.
final class Report404Controller extends ControllerBase {

  private const PER_PAGE = 25;

  public function __construct(
    private readonly Report404Log $log,
    private readonly PagerManagerInterface $pagerManager,
  ) {}

  public function view(): array {
    $counts = $this->log->readCounts();

    if (!$counts) {
      return [
        '#markup' => '<p>' . $this->t('Nessun 404 registrato dall\'ultimo azzeramento.') . '</p>',
      ];
    }

    $totale = array_sum($counts);

    $pager = $this->pagerManager->createPager(count($counts), self::PER_PAGE);
    $page = $pager->getCurrentPage();
    $pageCounts = array_slice($counts, $page * self::PER_PAGE, self::PER_PAGE, TRUE);

    $rows = [];
    foreach ($pageCounts as $uri => $count) {
      $rows[] = [$count, $uri];
    }

    return [
      'azzera' => [
        '#type' => 'link',
        '#title' => $this->t('Azzera log 404'),
        '#url' => Url::fromRoute('ildeposito_redirects.report404_azzera'),
        '#attributes' => ['class' => ['button', 'button--danger']],
      ],
      'summary' => [
        '#markup' => '<p>' . $this->formatPlural(
          $totale,
          '1 occorrenza su @unique URL uniche dall\'ultimo azzeramento.',
          '@count occorrenze su @unique URL uniche dall\'ultimo azzeramento.',
          ['@unique' => count($counts)],
        ) . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Occorrenze'), $this->t('URL')],
        '#rows' => $rows,
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

}
