<?php

declare(strict_types=1);

namespace Drupal\ildeposito_build\Drush\Commands;

use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scrive un evento nel log Drupal (canale ildeposito_build) da ildeposito.sh,
 * l'unico punto per cui passano tutte le build indipendentemente dalla fonte
 * del trigger (pulsante backend, run manuale su GitHub, esecuzione diretta
 * da server/crontab) — vedi ildeposito.sh: log_build_event().
 */
#[AsCommand(
  name: self::NAME,
  description: 'Scrive un messaggio nel log Drupal sotto il canale ildeposito_build.',
)]
final class BuildLogCommand extends Command {

  use AutowireTrait;

  public const NAME = 'ildeposito:log';

  protected function configure(): void {
    $this
      ->addArgument('message', InputArgument::REQUIRED, 'Messaggio da scrivere nel log')
      ->addOption('level', NULL, InputOption::VALUE_REQUIRED, 'Livello (info|warning|error)', 'info');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $message = (string) $input->getArgument('message');
    $level = (string) $input->getOption('level');

    $logger = \Drupal::logger('ildeposito_build');
    match ($level) {
      'error' => $logger->error($message),
      'warning' => $logger->warning($message),
      default => $logger->info($message),
    };

    return Command::SUCCESS;
  }

}
