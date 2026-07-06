<?php

declare(strict_types=1);

namespace Drupal\ildeposito_redirects\Hook;

use Drupal\Core\Hook\Attribute\Hook;

final class IldepositoRedirectsHooks {

  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    if ($key !== 'report_404') {
      return;
    }

    $counts = $params['counts'] ?? [];
    $totale = $params['totale'] ?? 0;

    $message['subject'] = sprintf('[ilDeposito] Report 404 — %d occorrenze', $totale);

    $lines = [
      sprintf('Riepilogo 404 registrati dall\'ultimo report (%d URI unici, %d occorrenze totali):', count($counts), $totale),
      '',
    ];

    foreach ($counts as $uri => $count) {
      $lines[] = sprintf('%5d  %s', $count, $uri);
    }

    $message['body'][] = implode("\n", $lines);
  }

}
