<?php

/**
 * Fix una tantum: valorizza field_links, field_anno_di_nascita e
 * field_anno_di_morte dei nodi 'autore' con i dati da autori_dati.csv
 * (root del repo), matchando gli autori per titolo del nodo. Non tocca
 * "changed" né crea nuove revisioni.
 *
 * Per ogni riga del CSV:
 * - field_anno_di_nascita / field_anno_di_morte vengono impostati solo se
 *   la colonna Nascita/Morte non è vuota; se è vuota il valore esistente
 *   sul nodo resta invariato (il CSV non è considerato fonte autoritativa
 *   per l'assenza di dato).
 * - field_links viene sostituito interamente con un solo link (uri dal
 *   CSV, titolo "Wikipedia") solo se la colonna Url non è vuota.
 * - righe senza alcun dato compilabile (Url/Nascita/Morte tutte vuote)
 *   vengono saltate.
 * - il match per titolo è esatto, con fallback a spazi normalizzati (nel
 *   CSV alcuni titoli hanno spaziature diverse da quelle nei nodi, es.
 *   "Carlos Mejia Godoy" vs "Carlos  Mejia Godoy").
 * - titoli non trovati vengono riportati a fine esecuzione per controllo
 *   manuale.
 *
 * Uso:
 *   ddev drush scr backend/script/update-autori-dati.php                # dry-run, solo report
 *   ddev drush scr backend/script/update-autori-dati.php -- --apply     # applica e salva
 */

$apply = in_array('--apply', $extra ?? [], true);

$csv_path = dirname(DRUPAL_ROOT, 2) . '/autori_dati.csv';
if (!file_exists($csv_path)) {
  throw new \RuntimeException("File CSV non trovato: $csv_path");
}

function normalize_title(string $title): string {
  return preg_replace('/\s+/', ' ', trim($title));
}

$storage = \Drupal::entityTypeManager()->getStorage('node');

// Indicizza tutti gli autori per titolo esatto e per titolo normalizzato.
$autori_nids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'autore')
  ->execute();

$by_title = [];
$by_normalized = [];
foreach ($storage->loadMultiple($autori_nids) as $node) {
  $title = $node->label();
  $by_title[$title] = $node;
  $by_normalized[normalize_title($title)] = $node;
}

$handle = fopen($csv_path, 'r');
if ($handle === FALSE) {
  throw new \RuntimeException("Impossibile aprire il CSV: $csv_path");
}
fgetcsv($handle); // Salta l'header.

$updated = [];
$skipped_no_data = [];
$not_found = [];

while (($row = fgetcsv($handle)) !== FALSE) {
  if (count($row) < 2) {
    continue;
  }
  [, $titolo, $url, $nascita, $morte] = array_pad($row, 5, '');
  $titolo = trim($titolo);
  $url = trim($url);
  $nascita = trim($nascita);
  $morte = trim($morte);

  if ($titolo === '') {
    continue;
  }
  if ($url === '' && $nascita === '' && $morte === '') {
    $skipped_no_data[] = $titolo;
    continue;
  }

  $node = $by_title[$titolo] ?? $by_normalized[normalize_title($titolo)] ?? NULL;
  if (!$node) {
    $not_found[] = $titolo;
    continue;
  }

  $fields_changed = [];

  if ($nascita !== '') {
    $node->set('field_anno_di_nascita', (int) $nascita);
    $fields_changed[] = 'anno_di_nascita=' . $nascita;
  }
  if ($morte !== '') {
    $node->set('field_anno_di_morte', (int) $morte);
    $fields_changed[] = 'anno_di_morte=' . $morte;
  }
  if ($url !== '') {
    $node->set('field_links', [['uri' => $url, 'title' => 'Wikipedia']]);
    $fields_changed[] = 'links=' . $url;
  }

  // Nessuna nuova revisione, nessun aggiornamento di "changed".
  $changed_time = $node->getChangedTime();
  $node->setNewRevision(FALSE);
  $node->setChangedTime($changed_time);

  $updated[] = $node->label() . ' (nid ' . $node->id() . '): ' . implode(', ', $fields_changed);
  if ($apply) {
    $node->save();
  }
}
fclose($handle);

$mode = $apply ? 'APPLICATO' : 'DRY-RUN (nessuna modifica salvata)';
echo "\n=== Aggiornamento dati autori da CSV [$mode] ===\n";
echo "Da aggiornare: " . count($updated) . "\n";
echo "Saltati (nessun dato nel CSV): " . count($skipped_no_data) . "\n";
echo "Non trovati nel DB: " . count($not_found) . "\n\n";

if ($updated) {
  echo "--- Aggiornati ---\n";
  foreach ($updated as $line) {
    echo "  $line\n";
  }
  echo "\n";
}

if ($not_found) {
  echo "--- Non trovati (controllo manuale) ---\n";
  foreach ($not_found as $t) {
    echo "  $t\n";
  }
  echo "\n";
}
