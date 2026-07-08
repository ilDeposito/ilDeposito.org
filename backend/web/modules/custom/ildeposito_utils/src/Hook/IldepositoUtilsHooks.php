<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;

final class IldepositoUtilsHooks {

  #[Hook('entity_presave')]
  public function entityPresave(EntityInterface $entity): void {
    if ($entity instanceof NodeInterface && $entity->bundle() === 'autore') {
      $this->setAutoreTitle($entity);
    }
  }

  /**
   * Calcola il titolo del nodo autore da nome e cognome.
   *
   * Il titolo va tenuto in sync automaticamente perché è il campo usato
   * per il display (link, breadcrumb, SEO) mentre editorialmente i dati
   * anagrafici si inseriscono in field_nome/field_cognome.
   */
  private function setAutoreTitle(NodeInterface $node): void {
    $nome = trim((string) $node->get('field_nome')->value);
    $cognome = trim((string) $node->get('field_cognome')->value);

    $titolo = trim("$nome $cognome");
    if ($titolo === '') {
      return;
    }

    $node->setTitle($titolo);
  }

  /**
   * Implements hook_preprocess_menu_region__middle().
   *
   * Nella voce "Crea" del toolbar Gin, per il ruolo staff mostra solo i link
   * di creazione contenuto (nodi), nascondendo media e termini di tassonomia
   * che compaiono automaticamente in base ai permessi di editing.
   *
   * @see \Drupal\gin\GinNavigation::getNavigationCreateMenuItems()
   */
  #[Hook('preprocess_menu_region__middle')]
  public function preprocessMenuRegionMiddle(array &$variables): void {
    if (($variables['menu_name'] ?? NULL) !== 'create') {
      return;
    }

    if (!\in_array('staff', \Drupal::currentUser()->getRoles(), TRUE)) {
      return;
    }

    if (!isset($variables['items']['create']['below'])) {
      return;
    }

    $variables['items']['create']['below'] = array_filter(
      $variables['items']['create']['below'],
      static fn (array $item): bool => !\in_array($item['class'] ?? NULL, ['media', 'taxonomy'], TRUE),
    );
  }

}
