<?php

/**
 * Fix una tantum per 3 link rotti isolati (emersi dal linkcheck frontend,
 * residui dopo fix-archivio-links.php):
 *
 * 1. "/canti/belaja-armija-cernyj-baron-1" -> "/canti/belaja-armija-cernyj-baron"
 *    (suffisso "-1" di un duplicato Pathauto non più esistente).
 * 2. "/ricerca?key=..." -> "/cerca?q=..." (route rinominata, parametro rinominato).
 * 3. link a "/blog/al-referendum-rispondiamo-no" (vecchio post di blog non più
 *    esistente) -> rimosso il tag <a>, mantenuto solo il testo.
 *
 * Uso:
 *   ddev drush scr backend/script/fix-misc-links.php                # dry-run, solo report
 *   ddev drush scr backend/script/fix-misc-links.php -- --apply     # applica e salva
 */

$apply = in_array('--apply', $extra ?? [], true);

$database = \Drupal::database();
$fields = ['field_informazioni', 'field_fonte'];

$needles = ['belaja-armija-cernyj-baron-1', 'ricerca?key=', 'blog/al-referendum-rispondiamo-no'];

$candidate_nids = [];
foreach ($fields as $field_name) {
  $table = 'node__' . $field_name;
  if (!$database->schema()->tableExists($table)) {
    continue;
  }
  $col_name = $field_name . '_value';
  foreach ($needles as $needle) {
    $rows = $database->select($table, 't')
      ->fields('t', ['entity_id'])
      ->condition($col_name, '%' . $database->escapeLike($needle) . '%', 'LIKE')
      ->condition('deleted', 0)
      ->execute();
    foreach ($rows as $row) {
      $candidate_nids[$row->entity_id] = TRUE;
    }
  }
}

function apply_fixes(string $value, array &$counts): string {
  // 1. Slug con suffisso "-1" residuo.
  $value = preg_replace_callback(
    '~(/canti/belaja-armija-cernyj-baron)-1\b~i',
    function ($m) use (&$counts) {
      $counts['belaja']++;
      return $m[1];
    },
    $value
  );

  // 2. /ricerca?key= -> /cerca?q=
  $value = preg_replace_callback(
    '~/ricerca\?key=~i',
    function ($m) use (&$counts) {
      $counts['ricerca']++;
      return '/cerca?q=';
    },
    $value
  );

  // 3. Rimuove il link al vecchio post di blog, mantiene solo il testo.
  $value = preg_replace_callback(
    '~<a\s+[^>]*href=(["\'])(?:https?://[^/"\']+)?/blog/al-referendum-rispondiamo-no\1[^>]*>(.*?)</a>~i',
    function ($m) use (&$counts) {
      $counts['referendum']++;
      return $m[2];
    },
    $value
  );

  return $value;
}

$updated_entities = 0;
$counts = ['belaja' => 0, 'ricerca' => 0, 'referendum' => 0];

foreach (array_keys($candidate_nids) as $nid) {
  $node = \Drupal\node\Entity\Node::load($nid);
  if (!$node) {
    continue;
  }
  $entity_changed = FALSE;

  foreach ($node->getTranslationLanguages() as $langcode => $language) {
    $translation = $node->getTranslation($langcode);
    foreach ($fields as $field_name) {
      if (!$translation->hasField($field_name)) {
        continue;
      }
      $field = $translation->get($field_name);
      $values = $field->getValue();
      $field_changed = FALSE;

      foreach ($values as $delta => $value) {
        foreach ($value as $prop => $prop_value) {
          if (!is_string($prop_value) || $prop_value === '') {
            continue;
          }
          $new = apply_fixes($prop_value, $counts);
          if ($new !== $prop_value) {
            $values[$delta][$prop] = $new;
            $field_changed = TRUE;
          }
        }
      }

      if ($field_changed) {
        $translation->set($field_name, $values);
        $entity_changed = TRUE;
      }
    }
  }

  if ($entity_changed) {
    $updated_entities++;
    if ($apply) {
      $node->save();
    }
  }
}

$mode = $apply ? 'APPLICATO' : 'DRY-RUN (nessuna modifica salvata)';
echo "\n=== Fix link isolati [$mode] ===\n";
echo "Entità aggiornate: $updated_entities\n";
echo "belaja-armija-cernyj-baron-1 -> senza suffisso: {$counts['belaja']}\n";
echo "/ricerca?key= -> /cerca?q=: {$counts['ricerca']}\n";
echo "Link blog/al-referendum-rispondiamo-no rimossi: {$counts['referendum']}\n";
