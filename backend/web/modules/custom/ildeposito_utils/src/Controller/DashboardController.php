<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\system\SystemManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dashboard dei redattori, basata sulle voci di primo/secondo livello del
 * menu "staff".
 *
 * Riusa gli stessi theme hook di /admin/config (admin_page, admin_block,
 * admin_block_content) cosi' lo stile a card arriva gratis da Claro/Gin,
 * senza CSS custom.
 *
 * @see \Drupal\system\Controller\SystemController::overview()
 */
final class DashboardController extends ControllerBase {

  public function __construct(
    private readonly MenuLinkTreeInterface $menuLinkTree,
    private readonly SystemManager $systemManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('menu.link_tree'),
      $container->get('system.manager'),
    );
  }

  public function build(): array {
    $parameters = new MenuTreeParameters();
    $parameters->setTopLevelOnly()->onlyEnabledLinks();
    $tree = $this->menuLinkTree->load('staff', $parameters);

    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];
    $tree = $this->menuLinkTree->transform($tree, $manipulators);

    $tree_access_cacheability = new CacheableMetadata();
    $blocks = [];
    foreach ($tree as $key => $element) {
      $tree_access_cacheability = $tree_access_cacheability->merge(CacheableMetadata::createFromObject($element->access));

      if (!$element->access->isAllowed()) {
        continue;
      }

      $link = $element->link;
      $block = [
        'title' => $link->getTitle(),
        'description' => $link->getDescription(),
        'content' => [
          '#theme' => 'admin_block_content',
          '#content' => $this->systemManager->getAdminBlock($link),
        ],
      ];

      if (!empty($block['content']['#content'])) {
        $blocks[$key] = $block;
      }
    }

    if (!$blocks) {
      $build = [
        '#markup' => $this->t('Nessuna voce disponibile nel menu "staff".'),
      ];
      $tree_access_cacheability->applyTo($build);
      return $build;
    }

    ksort($blocks);
    $build = [
      '#theme' => 'admin_page',
      '#blocks' => $blocks,
    ];
    $tree_access_cacheability->applyTo($build);

    return $build;
  }

}
