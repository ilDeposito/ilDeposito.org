<?php

declare(strict_types=1);

namespace Drupal\ildeposito_stats\Drush\Commands;

use Drupal\ildeposito_stats\Service\EntityUrlMatcher;
use Drupal\ildeposito_stats\Service\UmamiClient;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
  name: self::NAME,
  description: 'Risolve le visite Umami delle ultime N ore ai contenuti Drupal corrispondenti e le stampa ordinate per più viste.',
  aliases: ['ius'],
)]
final class UmamiStatsCommand extends Command {

  use AutowireTrait;

  public const NAME = 'ildeposito:umami-stats';

  public function __construct(
    private readonly UmamiClient $umamiClient,
    private readonly EntityUrlMatcher $entityUrlMatcher,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this->addOption('hours', NULL, InputOption::VALUE_REQUIRED, 'Finestra temporale in ore', 6);
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    if (!$this->umamiClient->isConfigured()) {
      $output->writeln('<error>Umami non configurato: impostare UMAMI_API_URL, UMAMI_USERNAME, UMAMI_PASSWORD, UMAMI_WEBSITE_ID.</error>');
      return Command::FAILURE;
    }

    $hours = (int) $input->getOption('hours');

    try {
      $rows = $this->umamiClient->getPageviewsByUrl($hours);
    }
    catch (\Throwable $e) {
      $output->writeln(sprintf('<error>Errore nella chiamata a Umami: %s</error>', $e->getMessage()));
      return Command::FAILURE;
    }

    if ($rows === []) {
      $output->writeln(sprintf('Nessuna visita registrata nelle ultime %d ore.', $hours));
      return Command::SUCCESS;
    }

    $matched = [];
    $unmatched = [];
    foreach ($rows as $row) {
      $entity = $this->entityUrlMatcher->match($row['url']);
      if (!$entity) {
        $unmatched[] = $row;
        continue;
      }

      $key = $entity->getEntityTypeId() . ':' . $entity->id();
      $matched[$key]['entity'] ??= $entity;
      $matched[$key]['visite'] = ($matched[$key]['visite'] ?? 0) + $row['visite'];
    }

    if ($matched === []) {
      $output->writeln(sprintf('Nessuna corrispondenza Drupal per le %d URL nelle ultime %d ore.', count($rows), $hours));
      return Command::SUCCESS;
    }

    $matched = array_values($matched);
    usort($matched, static fn(array $a, array $b): int => $b['visite'] <=> $a['visite']);

    $output->writeln(sprintf(
      '<info>%d contenuti Drupal trovati (su %d URL, nelle ultime %d ore):</info>',
      count($matched),
      count($rows),
      $hours,
    ));
    foreach ($matched as $row) {
      $entity = $row['entity'];
      $output->writeln(sprintf(
        '%6d  [%s:%s #%d]  %s',
        $row['visite'],
        $entity->getEntityTypeId(),
        $entity->bundle(),
        $entity->id(),
        $entity->label(),
      ));
    }

    if ($unmatched !== []) {
      usort($unmatched, static fn(array $a, array $b): int => $b['visite'] <=> $a['visite']);

      $output->writeln('');
      $output->writeln(sprintf('<comment>%d URL senza corrispondenza in Drupal:</comment>', count($unmatched)));
      foreach ($unmatched as $row) {
        $output->writeln(sprintf('%6d  %s', $row['visite'], $row['url']));
      }
    }

    return Command::SUCCESS;
  }

}
