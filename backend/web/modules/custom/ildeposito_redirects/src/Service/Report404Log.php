<?php

declare(strict_types=1);

namespace Drupal\ildeposito_redirects\Service;

final class Report404Log {

  // Percorso di sola lettura per nginx (frontend-web), ma scrivibile da
  // questo container: vedi backend/compose.yml, volume ildeposito_404_log
  // condiviso in read-write (necessario per l'azzeramento, vedi truncate()).
  private const LOG_PATH = '/var/log/frontend-nginx/404.log';

  // Estensioni di asset statici (immagini, CSS, JS) da escludere dal report:
  // interessano solo i 404 di pagina, il rumore di risorse mancanti (spesso
  // referrer esterni o crawler) non è azionabile.
  private const ESTENSIONI_IGNORATE = [
    'css', 'js', 'mjs', 'map',
    'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico',
  ];

  /**
   * @return array<string, int>
   *   Mappa URI => numero di occorrenze, ordinata per frequenza decrescente.
   */
  public function readCounts(): array {
    if (!is_readable(self::LOG_PATH)) {
      return [];
    }

    $contents = trim((string) file_get_contents(self::LOG_PATH));
    if ($contents === '') {
      return [];
    }

    $counts = [];
    foreach (explode("\n", $contents) as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      // Formato riga (vedi log_format "notfound" in frontend/nginx.conf):
      // "2026-01-01T12:00:00+01:00 /path/richiesto"
      $spacePos = strpos($line, ' ');
      $uri = $spacePos === FALSE ? $line : substr($line, $spacePos + 1);
      if ($this->isAsset($uri)) {
        continue;
      }
      $counts[$uri] = ($counts[$uri] ?? 0) + 1;
    }

    arsort($counts);
    return $counts;
  }

  public function truncate(): void {
    file_put_contents(self::LOG_PATH, '');
  }

  /**
   * Elimina dal log tutte le righe le cui URI sono nell'elenco dato.
   *
   * @param string[] $uris
   *   URI esatte (stesso formato restituito da readCounts()) da rimuovere.
   *
   * @return int
   *   Numero di righe (occorrenze) rimosse dal log.
   */
  public function deleteUris(array $uris): int {
    if (!$uris || !is_readable(self::LOG_PATH)) {
      return 0;
    }

    $uriSet = array_flip($uris);
    $contents = trim((string) file_get_contents(self::LOG_PATH));
    if ($contents === '') {
      return 0;
    }

    $kept = [];
    $removed = 0;
    foreach (explode("\n", $contents) as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $spacePos = strpos($line, ' ');
      $uri = $spacePos === FALSE ? $line : substr($line, $spacePos + 1);
      if (isset($uriSet[$uri])) {
        $removed++;
        continue;
      }
      $kept[] = $line;
    }

    file_put_contents(self::LOG_PATH, $kept ? implode("\n", $kept) . "\n" : '');
    return $removed;
  }

  private function isAsset(string $uri): bool {
    $path = parse_url($uri, PHP_URL_PATH) ?: $uri;
    $estensione = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($estensione, self::ESTENSIONI_IGNORATE, TRUE);
  }

}
