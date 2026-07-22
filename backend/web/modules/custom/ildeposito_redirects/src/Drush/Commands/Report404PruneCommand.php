<?php

declare(strict_types=1);

namespace Drupal\ildeposito_redirects\Drush\Commands;

use Drupal\ildeposito_redirects\Service\Report404Log;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Poda il log dei 404 (Report404Log): a differenza dell'azzeramento manuale
 * da UI, pensato per girare da crontab host, stesso pattern di
 * FbPostCommand/backup del sito (retention temporale, vedi ildeposito.sh) —
 * lo scheduling di questo progetto è deliberatamente sul crontab macchina,
 * non su hook_cron Drupal (vedi CRONTAB vuoto in backend/compose.yml).
 */
#[AsCommand(
  name: self::NAME,
  description: 'Rimuove dal log dei 404 le occorrenze più vecchie di N giorni (default 60).',
  aliases: ['iur404prune'],
)]
final class Report404PruneCommand extends Command {

  use AutowireTrait;

  public const NAME = 'ildeposito:report404-prune';

  private const DEFAULT_DAYS = 60;

  public function __construct(
    private readonly Report404Log $log,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this->addOption(
      'days',
      NULL,
      InputOption::VALUE_REQUIRED,
      'Soglia di retention in giorni: le occorrenze più vecchie vengono rimosse.',
      self::DEFAULT_DAYS,
    );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $days = (int) $input->getOption('days');

    $removed = $this->log->pruneOlderThan($days);

    $output->writeln(sprintf(
      '<info>%d occorrenze rimosse dal log dei 404 (più vecchie di %d giorni).</info>',
      $removed,
      $days,
    ));

    return Command::SUCCESS;
  }

}
