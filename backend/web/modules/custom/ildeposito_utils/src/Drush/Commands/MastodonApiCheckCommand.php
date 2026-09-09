<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Drush\Commands;

use Drupal\ildeposito_utils\Service\MastodonClient;
use Drush\Commands\AutowireTrait;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Verifica l'accesso API a Mastodon senza pubblicare alcun contenuto.
 */
#[AsCommand(
  name: self::NAME,
  description: 'Verifica il token Mastodon configurato, senza pubblicare contenuti.',
  aliases: ['iumastodoncheck'],
)]
final class MastodonApiCheckCommand extends Command {

  use AutowireTrait;

  public const NAME = 'ildeposito:mastodon-api-check';

  public function __construct(
    private readonly MastodonClient $mastodonClient,
  ) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    if (!$this->mastodonClient->isConfigured()) {
      return $this->reportFailure($output, 'configurazione assente (servono MASTODON_BASE_URL e MASTODON_ACCESS_TOKEN)');
    }

    try {
      $account = $this->mastodonClient->verifyCredentials();
    }
    catch (\Throwable $exception) {
      return $this->reportFailure($output, $this->getSafeErrorMessage($exception));
    }

    $acct = (string) $account['acct'];
    $output->writeln(sprintf('<info>Mastodon raggiungibile: token verificato per @%s.</info>', $acct));
    return Command::SUCCESS;
  }

  private function reportFailure(OutputInterface $output, string $reason): int {
    $message = 'Controllo API Mastodon fallito: ' . $reason;
    \Drupal::logger('ildeposito_utils')->error($message);
    $output->writeln('<error>' . $message . '</error>');
    return Command::FAILURE;
  }

  /**
   * Evita di scrivere in output URL completi, intestazioni o token.
   */
  private function getSafeErrorMessage(\Throwable $exception): string {
    if ($exception instanceof RequestException && $exception->getResponse() !== NULL) {
      return sprintf('Mastodon ha risposto HTTP %d.', $exception->getResponse()->getStatusCode());
    }

    return sprintf('errore interno durante la richiesta (%s)', $exception::class);
  }

}
