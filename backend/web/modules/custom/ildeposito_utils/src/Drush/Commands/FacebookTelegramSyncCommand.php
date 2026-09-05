<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Drush\Commands;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
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

  private const STATE_BASELINE = 'ildeposito_utils.facebook_telegram_sync_baseline';

  private const STATE_LAST_SYNC = 'ildeposito_utils.facebook_telegram_sync_last_sync';

  public function __construct(
    private readonly FacebookPageClient $facebookPageClient,
    private readonly FacebookTelegramPublisher $publisher,
    private readonly QueueFactory $queueFactory,
    private readonly StateInterface $state,
  ) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    if (!$this->publisher->isConfigured()) {
      $output->writeln('<comment>Replica Facebook -> Telegram non configurata.</comment>');
      return Command::SUCCESS;
    }

    $now = time();
    $baseline = (int) $this->state->get(self::STATE_BASELINE, 0);
    if ($baseline <= 0) {
      // La prima esecuzione e' deliberatamente silenziosa: il fallback non
      // deve ripubblicare contenuti Facebook antecedenti all'attivazione.
      $this->state->set(self::STATE_BASELINE, $now);
      $this->state->set(self::STATE_LAST_SYNC, $now);
      $output->writeln('<info>Baseline inizializzata: nessun post Facebook accodato.</info>');
      return Command::SUCCESS;
    }

    $lastSync = (int) $this->state->get(self::STATE_LAST_SYNC, $baseline);
    // Sovrapposizione di 10 minuti per eventuali ritardi Meta/cron, senza
    // mai scendere prima dell'istante in cui la replica e' stata attivata.
    $cutoff = max($baseline + 1, $lastSync - 600);

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
    $this->state->set(self::STATE_LAST_SYNC, $now);
    $output->writeln(sprintf('<info>%d post Facebook successivi alla baseline accodati.</info>', $queued));
    return Command::SUCCESS;
  }

}
