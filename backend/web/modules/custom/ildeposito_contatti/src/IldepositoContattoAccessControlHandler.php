<?php

declare(strict_types=1);

namespace Drupal\ildeposito_contatti;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

class IldepositoContattoAccessControlHandler extends EntityAccessControlHandler {

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    // L'admin bypassa tutto; EntityAccessControlHandler::access() verifica
    // admin_permission PRIMA di chiamare questo metodo, ma lo ripetiamo
    // esplicitamente per chiarezza.
    if ($account->hasPermission('administer ildeposito contatti')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermission($account, 'view ildeposito contatti'),
      'delete' => AccessResult::allowedIfHasPermission($account, 'delete ildeposito contatti'),
      // Modifica riservata agli admin (nessun permesso granulare per ora).
      default => AccessResult::forbidden()->cachePerPermissions(),
    };
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    // Le submission arrivano via JSON:API standard (vedi routing.yml), che
    // invoca questo check prima di creare l'entity: è la vera barriera che
    // autorizza le scritture, non solo un permesso per eventuali form admin
    // manuali. Chi arriva a valutarlo è autenticato via HTTP Basic Auth
    // (Astro server-side, sopra al basic auth Caddy infrastrutturale); vedi
    // JsonApiWriteFirewall (ildeposito_utils) per il blocco degli anonimi.
    return AccessResult::allowedIfHasPermission($account, 'administer ildeposito contatti')
      ->orIf(AccessResult::allowedIfHasPermission($account, 'create ildeposito contatti'));
  }

}
