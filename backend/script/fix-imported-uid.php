<?php

/**
 * Fix una tantum: i contenuti importati con migrate sono stati salvati con
 * uid=1 (default quando la migrazione non mappa un autore). Riassegna
 * uid=3 a tutti i nodi e le entità media con uid=1, senza toccare "changed"
 * né creare nuove revisioni.
 *
 * Nota: le taxonomy term di Drupal core non hanno un campo uid (nessun
 * autore), quindi non sono incluse in questo fix.
 *
 * Uso:
 *   ddev drush scr backend/script/fix-imported-uid.php                # dry-run, solo report
 *   ddev drush scr backend/script/fix-imported-uid.php -- --apply     # applica e salva
 */

$apply = in_array('--apply', $extra ?? [], true);

const OLD_UID = 1;
const NEW_UID = 3;

function fix_uid_for_entity_type(string $entity_type_id, bool $apply): array {
  $storage = \Drupal::entityTypeManager()->getStorage($entity_type_id);

  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('uid', OLD_UID)
    ->execute();

  $updated = 0;
  foreach (array_chunk($ids, 50) as $chunk) {
    $storage->resetCache($chunk);
    foreach ($storage->loadMultiple($chunk) as $entity) {
      if ((int) $entity->getOwnerId() !== OLD_UID) {
        continue;
      }
      $changed = $entity->hasField('changed') ? $entity->getChangedTime() : NULL;
      $entity->setOwnerId(NEW_UID);
      if ($changed !== NULL) {
        $entity->setChangedTime($changed);
      }
      if ($entity instanceof \Drupal\Core\Entity\RevisionableInterface) {
        $entity->setNewRevision(FALSE);
      }
      $updated++;
      if ($apply) {
        $entity->save();
      }
    }
  }

  return [count($ids), $updated];
}

$mode = $apply ? 'APPLICATO' : 'DRY-RUN (nessuna modifica salvata)';
echo "\n=== Fix uid importati con migrate ({$mode}) ===\n";
echo "Da uid=" . OLD_UID . " a uid=" . NEW_UID . "\n\n";

foreach (['node', 'media'] as $entity_type_id) {
  [$found, $updated] = fix_uid_for_entity_type($entity_type_id, $apply);
  echo "[{$entity_type_id}] trovati con uid=" . OLD_UID . ": {$found} — aggiornati: {$updated}\n";
}

echo "\nNota: le taxonomy term non hanno campo uid, escluse dal fix.\n";
