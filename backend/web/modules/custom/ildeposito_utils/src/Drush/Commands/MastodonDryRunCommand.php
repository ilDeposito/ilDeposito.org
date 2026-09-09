<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Drush\Commands;

use Drupal\ildeposito_utils\Service\FacebookMastodonPublisher;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: self::NAME, description: 'Simula la replica di un post Facebook su Mastodon senza pubblicare.', aliases: ['iumastodondryrun'])]
final class MastodonDryRunCommand extends Command {
  use AutowireTrait;
  public const NAME = 'ildeposito:mastodon-dry-run';
  public function __construct(private readonly FacebookMastodonPublisher $publisher) { parent::__construct(); }
  protected function configure(): void { $this->addArgument('facebook-post-id', InputArgument::REQUIRED, 'ID del post Facebook da analizzare.'); }
  protected function execute(InputInterface $input, OutputInterface $output): int {
    try { $preview = $this->publisher->preview((string) $input->getArgument('facebook-post-id')); }
    catch (\Throwable $e) { $output->writeln('<error>Anteprima Mastodon fallita: ' . $e::class . '</error>'); return Command::FAILURE; }
    if (!$preview['allowed']) { $output->writeln('<comment>Escluso dalla politica Mastodon: ' . $preview['reason'] . '.</comment>'); return Command::SUCCESS; }
    $output->writeln(sprintf('<info>Pubblicabile: %d post%s, immagine: %s, alt text: %s</info>', $preview['parts'], $preview['parts'] === 1 ? '' : ' in thread', $preview['has_image'] ? 'sì' : 'no', $preview['alt'] === '' ? 'assente' : 'presente'));
    return Command::SUCCESS;
  }
}
