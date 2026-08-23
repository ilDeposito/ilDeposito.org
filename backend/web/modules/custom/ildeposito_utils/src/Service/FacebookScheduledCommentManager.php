<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Service;

use Drupal\Core\State\StateInterface;

/**
 * Memorizza e pubblica i commenti dei post Facebook programmati.
 *
 * Un post programmato non è subito commentabile: il cron ritenta il commento
 * dal momento previsto per la pubblicazione, finché Facebook lo accetta.
 */
final class FacebookScheduledCommentManager {

  private const STATE_KEY = 'ildeposito_utils.facebook_scheduled_comments';

  public function __construct(
    private readonly StateInterface $state,
    private readonly FacebookPageClient $facebookPageClient,
  ) {}

  /**
   * Aggiunge il commento da inviare non prima dell'orario del post.
   */
  public function schedule(string $post_id, string $message, int $available_at): void {
    $comments = $this->getPendingComments();
    $comments[$post_id] = [
      'post_id' => $post_id,
      'message' => $message,
      'available_at' => $available_at,
      'attempts' => 0,
    ];
    $this->state->set(self::STATE_KEY, $comments);
  }

  /**
   * Pubblica i commenti dei post che hanno raggiunto il proprio orario.
   */
  public function processDueComments(): int {
    if (!$this->facebookPageClient->isConfigured()) {
      return 0;
    }

    $comments = $this->getPendingComments();
    $now = time();
    $changed = FALSE;
    $published = 0;

    foreach ($comments as $post_id => $comment) {
      if ($comment['available_at'] > $now) {
        continue;
      }

      try {
        $this->facebookPageClient->post($comment['post_id'] . '/comments', [
          'message' => $comment['message'],
        ]);
        unset($comments[$post_id]);
        $changed = TRUE;
        $published++;
      }
      catch (\Throwable $exception) {
        // Facebook può rendere il post disponibile qualche istante dopo
        // l'orario richiesto. Lasciamo l'elemento in state: il cron seguente
        // riproverà senza perdere il commento.
        $comments[$post_id]['attempts']++;
        $changed = TRUE;
        \Drupal::logger('ildeposito_utils')->warning('Commento Facebook per post @post_id non ancora pubblicato o non inviabile (tentativo @attempt): @message', [
          '@post_id' => $post_id,
          '@attempt' => $comments[$post_id]['attempts'],
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    if ($changed) {
      $this->state->set(self::STATE_KEY, $comments);
    }

    return $published;
  }

  /**
   * @return array<string, array{post_id: string, message: string, available_at: int, attempts: int}>
   */
  private function getPendingComments(): array {
    $comments = $this->state->get(self::STATE_KEY, []);
    return is_array($comments) ? $comments : [];
  }

}
