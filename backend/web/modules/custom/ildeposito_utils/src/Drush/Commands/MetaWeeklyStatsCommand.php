<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Drush\Commands;

use Drupal\ildeposito_utils\Service\MetaWeeklyStatsReporter;
use Drush\Commands\AutowireTrait;
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

    $dryRun = (bool) $input->getOption('dry-run');
    if ($dryRun) {
      $preview = $this->reporter->preview();
      $output->writeln($preview ?? '<comment>Baseline assente: la prima esecuzione reale la inizializzerà senza inviare il riepilogo.</comment>');
      return Command::SUCCESS;
    }

    return match ($this->reporter->report()) {
      'baseline' => $this->write($output, 'Baseline inizializzata: il primo riepilogo sarà inviato dopo una settimana.'),
      'already_sent' => $this->write($output, 'Riepilogo della settimana già inviato.'),
      default => $this->write($output, 'Riepilogo social inviato su Telegram.'),
    };
  }

  private function write(OutputInterface $output, string $message): int {
    $output->writeln('<info>' . $message . '</info>');
    return Command::SUCCESS;
  }

}
