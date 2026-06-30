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
    // Il controller API bypassa questo check salvando l'entity direttamente
    // via storage (senza passare per EntityManager::create con access check).
    // La vera barriera di sicurezza per le submission API è il basic auth Caddy.
    // Questo permesso serve per eventuali form admin manuali.
    return AccessResult::allowedIfHasPermission($account, 'administer ildeposito contatti')
      ->orIf(AccessResult::allowedIfHasPermission($account, 'create ildeposito contatti'));
  }

}
