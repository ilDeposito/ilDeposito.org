<?php

/**
 * Fix una tantum: riscrive i link legacy "/archivio/{tipo}/{slug}" (tipo =
 * canti|autori|storiacantata) trovati nei campi rich-text (field_informazioni,
 * field_fonte) di canto/autore/evento, puntandoli all'alias attuale
 * risolto tramite path_alias. Non tocca i link "*.php?id=" (da fare a mano).
 *
 * Molti slug esistono sotto più alias (es. una canzone "la-carmagnole" e la
 * sua traduzione, oppure un canto e l'evento omonimo): in questi casi si
 * preferisce l'alias con lo stesso "tipo" del link legacy (canti→/canti/,
 * autori→/autori/, storiacantata→/eventi/); se non c'è un candidato con
 * quel tipo, il link resta irrisolto e va controllato a mano.
 *
 * Uso:
 *   ddev drush scr backend/script/fix-archivio-links.php                # dry-run, solo report
 *   ddev drush scr backend/script/fix-archivio-links.php -- --apply     # applica e salva
 */

$apply = in_array('--apply', $extra ?? [], true);

$database = \Drupal::database();
$transliteration = \Drupal::transliteration();

function normalize_slug(string $segment, $transliteration): string {
  $segment = urldecode($segment);
  $segment = $transliteration->transliterate($segment, 'it', '-');
  $segment = strtolower($segment);
  $segment = preg_replace('/[^a-z0-9]+/', '-', $segment);
  return trim($segment, '-');
}

function resolve_alias(string $norm, string $old_type, array $alias_map): ?string {
  if (empty($alias_map[$norm])) {
    return NULL;
  }
  $candidates = $alias_map[$norm];
  if (count($candidates) === 1) {
    return reset($candidates);
  }
  $priority = [
    'canti' => ['/canti/', '/traduzioni/', '/autori/', '/eventi/'],
    'autori' => ['/autori/', '/canti/'],
    'storiacantata' => ['/eventi/', '/canti/'],
  ][$old_type] ?? [];
  foreach ($priority as $prefix) {
    foreach ($candidates as $candidate) {
      if (str_starts_with($candidate, $prefix)) {
        return $candidate;
      }
    }
  }
  return NULL;
}

// 1. Lookup slug-normalizzato => tutti gli alias attuali con quello slug finale.
$alias_map = [];
$result = $database->select('path_alias', 'pa')
  ->fields('pa', ['alias'])
  ->condition('status', 1)
  ->execute();
foreach ($result as $row) {
  $segments = explode('/', trim($row->alias, '/'));
  $last = end($segments);
  $norm = normalize_slug($last, $transliteration);
  if ($norm === '') {
    continue;
  }
  $alias_map[$norm][$row->alias] = $row->alias;
}

// 2. Entità candidate: contengono "archivio" nei campi rich-text noti.
$fields = ['field_informazioni', 'field_fonte'];
$candidate_nids = [];
foreach ($fields as $field_name) {
  $table = 'node__' . $field_name;
  if (!$database->schema()->tableExists($table)) {
    continue;
  }
  $col_name = $field_name . '_value';
  $rows = $database->select($table, 't')
    ->fields('t', ['entity_id'])
    ->condition($col_name, '%archivio%', 'LIKE')
    ->condition('deleted', 0)
    ->execute();
  foreach ($rows as $row) {
    $candidate_nids[$row->entity_id] = TRUE;
  }
}

$pattern = '~href=(["\'])\s*(?:https?://[^/"\']+)?/archivio/(canti|autori|storiacantata)/([^"\'?#]+)(#[^"\']*)?\1~i';

$updated_entities = 0;
$replaced_links = 0;
$unresolved = [];

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
          if (!is_string($prop_value) || strpos($prop_value, 'archivio') === FALSE) {
            continue;
          }
          $original = $prop_value;
          $new = preg_replace_callback($pattern, function ($m) use (&$replaced_links, &$unresolved, $alias_map, $node, $field_name) {
            $quote = $m[1];
            $old_type = $m[2];
            $slug = $m[3];
            $fragment = $m[4] ?? '';
            $norm = normalize_slug($slug, \Drupal::transliteration());
            $alias = resolve_alias($norm, $old_type, $alias_map);
            if ($alias !== NULL) {
              $replaced_links++;
              return 'href=' . $quote . $alias . $fragment . $quote;
            }
            $unresolved[] = [
              'nid' => $node->id(),
              'title' => $node->label(),
              'field' => $field_name,
              'href' => $m[0],
              'candidati' => $alias_map[$norm] ?? [],
            ];
            return $m[0];
          }, $original);

          if ($new !== $original) {
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
echo "\n=== Fix link /archivio/... [$mode] ===\n";
echo "Entità con almeno un link da correggere: $updated_entities\n";
echo "Link risolti e riscritti: $replaced_links\n";
echo "Link NON risolti (serve controllo manuale): " . count($unresolved) . "\n\n";

if ($unresolved) {
  echo "--- Link non risolti ---\n";
  foreach ($unresolved as $u) {
    $cand = $u['candidati'] ? ' (candidati: ' . implode(', ', $u['candidati']) . ')' : ' (nessun alias trovato)';
    echo "  nid={$u['nid']} \"{$u['title']}\" [{$u['field']}]: {$u['href']}{$cand}\n";
  }
}
