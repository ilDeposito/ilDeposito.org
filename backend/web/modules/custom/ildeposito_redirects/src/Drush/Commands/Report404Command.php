<?php

declare(strict_types=1);

namespace Drupal\ildeposito_redirects\Drush\Commands;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
  name: self::NAME,
  description: 'Invia il report dei 404 registrati da nginx (raggruppati per URI, ordinati per frequenza) e tronca il log.',
  aliases: ['ir404'],
)]
final class Report404Command extends Command {

  use AutowireTrait;

  public const NAME = 'ildeposito:report-404';

  // Percorso di sola lettura per la scrittura di nginx (frontend-web), ma
  // scrivibile da questo container: vedi backend/compose.yml, volume
  // ildeposito_404_log condiviso in read-write (necessario per il troncamento
  // post-invio, vedi metodo execute()).
  private const LOG_PATH = '/var/log/frontend-nginx/404.log';
  private const STATE_DESTINATARI = 'report_404_destinatari';

  // Estensioni di asset statici (immagini, CSS, JS) da escludere dal report:
  // interessano solo i 404 di pagina, il rumore di risorse mancanti (spesso
  // referrer esterni o crawler) non è azionabile.
  private const ESTENSIONI_IGNORATE = [
    'css', 'js', 'mjs', 'map',
    'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico',
  ];

  public function __construct(
    private readonly StateInterface $state,
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
  ) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    if (!is_readable(self::LOG_PATH)) {
      $output->writeln(sprintf('<comment>Log 404 non trovato o non leggibile: %s</comment>', self::LOG_PATH));
      return Command::SUCCESS;
    }

    $contents = trim((string) file_get_contents(self::LOG_PATH));
    if ($contents === '') {
      $output->writeln('Nessun 404 registrato dall\'ultimo report.');
      return Command::SUCCESS;
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
    $totale = array_sum($counts);

    $destinatari = $this->getDestinatari();
    if (empty($destinatari)) {
      $output->writeln(sprintf(
        '<error>Nessun destinatario configurato in State (%s): report non inviato, log NON troncato.</error>',
        self::STATE_DESTINATARI,
      ));
      return Command::FAILURE;
    }

    $this->mailManager->mail(
      module: 'ildeposito_redirects',
      key: 'report_404',
      to: implode(', ', $destinatari),
      langcode: $this->languageManager->getDefaultLanguage()->getId(),
      params: ['counts' => $counts, 'totale' => $totale],
    );

    // Tronca SOLO dopo l'invio: in caso di errore SMTP silenzioso il rischio
    // è un doppio conteggio al prossimo report, mai la perdita dei dati
    // (troncare prima dell'invio sarebbe peggio).
    file_put_contents(self::LOG_PATH, '');

    $output->writeln(sprintf(
      '<info>Report 404 inviato (%d URI unici, %d occorrenze totali) a: %s</info>',
      count($counts),
      $totale,
      implode(', ', $destinatari),
    ));

    return Command::SUCCESS;
  }

  private function isAsset(string $uri): bool {
    $path = parse_url($uri, PHP_URL_PATH) ?: $uri;
    $estensione = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($estensione, self::ESTENSIONI_IGNORATE, TRUE);
  }

  private function getDestinatari(): array {
    $raw = (string) $this->state->get(self::STATE_DESTINATARI, '');
    if ($raw === '') {
      return [];
    }

    return array_values(array_filter(
      array_map('trim', explode(',', $raw)),
      static fn(string $addr): bool => filter_var($addr, FILTER_VALIDATE_EMAIL) !== FALSE,
    ));
  }

}
