<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Drush\Commands;

use Drupal\Core\Queue\QueueFactory;
use Drupal\ildeposito_utils\Service\FacebookPageClient;
use Drupal\ildeposito_utils\Service\FacebookTelegramPublisher;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Recupera i post recenti per coprire eventuali webhook Meta non consegnati.
 */
#[AsCommand(
  name: 'ildeposito:facebook-telegram-sync',
  description: 'Accoda i post Facebook recenti non ancora replicati su Telegram.',
  aliases: ['iufbtgsync'],
)]
final class FacebookTelegramSyncCommand extends Command {

  use AutowireTrait;

  private const QUEUE_NAME = 'ildeposito_utils_facebook_telegram';

  public function __construct(
    private readonly FacebookPageClient $facebookPageClient,
    private readonly FacebookTelegramPublisher $publisher,
    private readonly QueueFactory $queueFactory,
  ) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    if (!$this->publisher->isConfigured()) {
      $output->writeln('<comment>Replica Facebook -> Telegram non configurata.</comment>');
      return Command::SUCCESS;
    }

    $response = $this->facebookPageClient->getFromPage('feed', [
      'fields' => 'id,created_time',
      'limit' => 25,
    ]);
    try {
      $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException('Facebook ha restituito un feed non JSON.', 0, $exception);
    }

    $cutoff = time() - 900;
    $queued = 0;
    foreach ($payload['data'] ?? [] as $post) {
      $postId = is_array($post) ? ($post['id'] ?? NULL) : NULL;
      $created = is_array($post) ? strtotime((string) ($post['created_time'] ?? '')) : FALSE;
      if (!is_string($postId) || $postId === '' || $created === FALSE || $created < $cutoff) {
        continue;
      }
      $this->queueFactory->get(self::QUEUE_NAME)->createItem(['facebook_post_id' => $postId]);
      $queued++;
    }
    $output->writeln(sprintf('<info>%d post Facebook recenti accodati.</info>', $queued));
    return Command::SUCCESS;
  }

}
