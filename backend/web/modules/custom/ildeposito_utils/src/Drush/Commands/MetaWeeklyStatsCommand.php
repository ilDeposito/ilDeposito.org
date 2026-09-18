<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Drush\Commands;

use Drupal\ildeposito_utils\Service\MetaWeeklyStatsReporter;
use Drush\Commands\AutowireTrait;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Invia il riepilogo settimanale unico di Facebook e Instagram. */
#[AsCommand(
  name: 'ildeposito:meta-weekly-stats',
  description: 'Invia il riepilogo settimanale delle statistiche social su Telegram.',
  aliases: ['iumetaweeklystats'],
)]
final class MetaWeeklyStatsCommand extends Command {

  use AutowireTrait;

  public function __construct(
    private readonly MetaWeeklyStatsReporter $reporter,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this->addOption('dry-run', NULL, InputOption::VALUE_NONE, 'Mostra l’anteprima senza inviare messaggi né salvare lo stato.');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    if (!$this->reporter->isConfigured()) {
      $output->writeln('<comment>Riepilogo social non configurato in questo ambiente.</comment>');
      return Command::SUCCESS;
    }

    try {
      if (!$input->getOption('dry-run')) {
        $output->writeln('Rilevamento delle statistiche settimanali in corso…');
        return match ($this->reporter->report(FALSE, $this->progressWriter($output))) {
          'baseline' => $this->write($output, 'Baseline inizializzata: il primo riepilogo sarà inviato dopo una settimana.'),
          'already_sent' => $this->write($output, 'Riepilogo della settimana già inviato.'),
          default => $this->write($output, 'Riepilogo social inviato su Telegram.'),
        };
      }

      $output->writeln('Preparazione dell’anteprima del riepilogo…');
      $preview = $this->reporter->preview($this->progressWriter($output));
      $output->writeln($preview ?? '<comment>Baseline assente: la prima esecuzione reale la inizializzerà senza inviare il riepilogo.</comment>');
      return Command::SUCCESS;
    }
    catch (\Throwable $exception) {
      return $this->reportFailure($output, $this->getSafeErrorMessage($exception));
    }
  }

  private function write(OutputInterface $output, string $message): int {
    $output->writeln('<info>' . $message . '</info>');
    return Command::SUCCESS;
  }

  /** Ritorna il callback di avanzamento con cui il reporter aggiorna il passo corrente. */
  private function progressWriter(OutputInterface $output): \Closure {
    return static function (string $message) use ($output): void {
      $output->writeln('  › ' . $message);
    };
  }

  /**
   * Registra un errore Drupal, intercettato dal notifier Telegram in prod.
   */
  private function reportFailure(OutputInterface $output, string $reason): int {
    $message = 'Riepilogo settimanale Meta fallito: ' . $reason;
    \Drupal::logger('ildeposito_utils')->error($message);
    $output->writeln('<error>' . $message . '</error>');
    return Command::FAILURE;
  }

  /**
   * Produce un messaggio diagnostico privo di URL o parametri segreti.
   */
  private function getSafeErrorMessage(\Throwable $exception): string {
    if ($exception instanceof RequestException && $exception->getResponse() !== NULL) {
      $response = $exception->getResponse();
      try {
        $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException) {
        $payload = [];
      }

      $error = is_array($payload) ? ($payload['error'] ?? NULL) : NULL;
      if (is_string($error)) {
        return sprintf('la piattaforma ha risposto HTTP %d: %s', $response->getStatusCode(), $error);
      }
      if (is_array($error) && is_string($error['message'] ?? NULL)) {
        return sprintf('la piattaforma ha risposto HTTP %d: %s', $response->getStatusCode(), $error['message']);
      }

      return sprintf('la piattaforma ha risposto HTTP %d.', $response->getStatusCode());
    }

    // Le eccezioni di trasporto possono includere URL e query string: non
    // propaghiamole mai nel log, che in produzione viene inoltrato a Telegram.
    return sprintf('errore interno durante la richiesta (%s)', $exception::class);
  }

}
