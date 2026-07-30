<?php

/**
 * Aggiorna i nodi di tipo "autore" con i dati presi da backend/script/autori_dati.csv.
 *
 * Uso:
 *   ddev drush scr backend/script/update-autori-from-csv.php -- --dry-run
 *   ddev drush scr backend/script/update-autori-from-csv.php -- --file=backend/script/autori_dati.csv --dry-run
 *   ddev drush scr backend/script/update-autori-from-csv.php -- [--file=backend/script/autori_dati.csv]
 *
 * Alternativa con drush php:script:
 *   ddev drush php:script update-autori-from-csv --script-path=../script -- --dry-run
 *   ddev drush php:script update-autori-from-csv --script-path=../script -- --file=backend/script/autori_dati.csv
 *
 * Nota: il valore di --script-path è relativo alla root Drupal, che in DDEV è
 * backend/web. Se `ddev drush` continua a restituire "exit status 1" nonostante
 * lo script sia stato eseguito correttamente, usa invece:
 *   ddev exec bash -lc 'cd /var/www/html/backend/web && drush php:script ../script/update-autori-from-csv.php -- --dry-run'
 *
 * Se viene passato --dry-run il codice legge e valida i nodi senza salvare alcuna
 * modifica. Senza --dry-run le modifiche vengono salvate.
 *
 * Il CSV deve avere la prima riga con intestazioni e almeno queste colonne:
 *   Titolo, Url, Nascita, Morte
 */

$cli_args = [];
if (isset($extra) && is_array($extra)) {
  $cli_args = $extra;
}
elseif (isset($argv) && is_array($argv)) {
  $cli_args = array_slice($argv, 1);
}

$dry_run = in_array('--dry-run', $cli_args, true);
$file_path = __DIR__ . '/autori_dati.csv';

foreach ($cli_args as $arg) {
  if (strpos($arg, '--file=') === 0) {
    $file_path = substr($arg, strlen('--file='));
    break;
  }
}

if (!is_file($file_path) || !is_readable($file_path)) {
  echo "Errore: file CSV non trovato o non leggibile: {$file_path}\n";
  exit(1);
}

$handle = fopen($file_path, 'r');
if ($handle === false) {
  echo "Errore: impossibile aprire il file CSV: {$file_path}\n";
  exit(1);
}

$headers = fgetcsv($handle);
if ($headers === false) {
  echo "Errore: file CSV vuoto o intestazione non leggibile.\n";
  fclose($handle);
  exit(1);
}

$headers = array_map(static function ($value) {
  return trim((string) $value);
}, $headers);

$required_columns = ['Titolo', 'Url', 'Nascita', 'Morte'];
$missing_columns = array_diff($required_columns, $headers);
if ($missing_columns !== []) {
  echo "Errore: colonne mancanti nel CSV: " . implode(', ', $missing_columns) . "\n";
  fclose($handle);
  exit(1);
}

$header_map = array_flip($headers);
$row_number = 1;
$report = [
  'processed' => 0,
  'found' => 0,
  'updated' => 0,
  'not_found' => 0,
  'errors' => [],
];

function getCsvValue(array $row, array $header_map, string $column): string {
  return trim($row[$header_map[$column]] ?? '');
}

function normalizeTitle(string $title): string {
  return preg_replace('/\s+/', ' ', trim($title));
}

function buildTitleRegexp(string $title): string {
  $words = preg_split('/\s+/', normalizeTitle($title), -1, PREG_SPLIT_NO_EMPTY);
  if ($words === false || $words === []) {
    return '^$';
  }
  $escaped = array_map(static fn (string $word) => preg_quote($word, '/'), $words);
  return '^' . implode('[[:space:]]+', $escaped) . '$';
}

function findAuthorNids(string $title): array {
  $exact_nids = \Drupal::entityQuery('node')
    ->accessCheck(FALSE)
    ->condition('type', 'autore')
    ->condition('title', $title)
    ->execute();

  if (!empty($exact_nids)) {
    return $exact_nids;
  }

  $database = \Drupal::database();
  $regexp = buildTitleRegexp($title);
  $query = $database->select('node_field_data', 'n')
    ->fields('n', ['nid'])
    ->condition('type', 'autore')
    ->where('title REGEXP :regexp', [':regexp' => $regexp]);

  $found = [];
  foreach ($query->execute() as $row) {
    $found[] = (int) $row->nid;
  }

  return $found;
}

while (($row = fgetcsv($handle)) !== false) {
  $row_number++;

  if (count($row) === 1 && trim((string) $row[0]) === '') {
    continue;
  }

  $report['processed']++;
  $title = getCsvValue($row, $header_map, 'Titolo');
  $url = getCsvValue($row, $header_map, 'Url');
  $birth = getCsvValue($row, $header_map, 'Nascita');
  $death = getCsvValue($row, $header_map, 'Morte');

  if ($title === '') {
    $report['errors'][] = "Riga {$row_number}: titolo vuoto";
    continue;
  }

  $nids = findAuthorNids($title);
  if (empty($nids)) {
    $report['not_found']++;
    $report['errors'][] = "Riga {$row_number}: nodo autore non trovato con title='{$title}'";
    continue;
  }

  $report['found']++;
  $nid = reset($nids);
  if (count($nids) > 1) {
    $report['errors'][] = "Riga {$row_number}: più nodi trovati con title='{$title}', verrà usato nid={$nid}";
  }

  $node = \Drupal\node\Entity\Node::load($nid);
  if (!$node) {
    $report['errors'][] = "Riga {$row_number}: impossibile caricare il nodo nid={$nid}";
    continue;
  }

  $changed = false;

  if ($url !== '') {
    $new_link = [
      ['uri' => $url, 'title' => 'Wikipedia'],
    ];
    $current_links = $node->hasField('field_links') ? $node->get('field_links')->getValue() : [];
    if ($current_links !== $new_link) {
      $node->set('field_links', $new_link);
      $changed = true;
    }
  }

  if ($birth !== '' && $node->hasField('field_anno_di_nascita')) {
    $current_birth = trim((string) $node->get('field_anno_di_nascita')->value);
    if ($current_birth !== $birth) {
      $node->set('field_anno_di_nascita', $birth);
      $changed = true;
    }
  }

  if ($death !== '' && $node->hasField('field_anno_di_morte')) {
    $current_death = trim((string) $node->get('field_anno_di_morte')->value);
    if ($current_death !== $death) {
      $node->set('field_anno_di_morte', $death);
      $changed = true;
    }
  }

  if ($changed) {
    $node->setNewRevision(FALSE);
    if (!$dry_run) {
      $node->save();
    }
    $report['updated']++;
  }
}

fclose($handle);

$mode = $dry_run ? 'DRY-RUN (nessuna modifica salvata)' : 'APPLICATO';

echo "\n=== Report aggiornamento autori CSV ===\n";
echo "File CSV: {$file_path}\n";
echo "Modalità: {$mode}\n";
echo "Righe elaborate: {$report['processed']}\n";
echo "Nodi autori trovati: {$report['found']}\n";
echo "Nodi aggiornati: {$report['updated']}\n";
echo "Nodi non trovati: {$report['not_found']}\n";

if (!empty($report['errors'])) {
  echo "\nErrori / avvisi:\n";
  foreach ($report['errors'] as $error) {
    echo "- {$error}\n";
  }
}

if ($dry_run) {
  echo "\nNota: non è stato chiamato \$node->save() perché sei in modalità dry-run.\n";
}

exit(0);
