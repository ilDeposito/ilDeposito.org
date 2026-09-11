<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Service;

use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;

/** Inoltra nel gruppo notifiche Telegram commenti e menzioni Instagram. */
final class InstagramTelegramNotifier {

  private const STATE_PREFIX = 'ildeposito_utils.instagram_notification.';

  public function __construct(
    private readonly FacebookPageClient $facebookPageClient,
    private readonly ClientInterface $httpClient,
    private readonly StateInterface $state,
    private readonly MetaWeeklyStatsReporter $weeklyStatsReporter,
  ) {}

  /**
   * Inoltra una singola modifica consegnata dal webhook Instagram.
   *
   * @param array<string, mixed> $notification
   */
  public function notify(array $notification): void {
    if (!$this->isConfigured()) {
      throw new \LogicException('Notifiche Instagram non configurate.');
    }
    $field = $notification['field'] ?? NULL;
    $value = $notification['value'] ?? NULL;
    if (!in_array($field, ['comments', 'mentions'], TRUE) || !is_array($value)) {
      return;
    }

    $stateKey = self::STATE_PREFIX . hash('sha256', (string) $field . ':' . json_encode($value, JSON_THROW_ON_ERROR));
    if ($this->state->get($stateKey, FALSE)) {
      return;
    }

    $this->telegram($this->format($field, $value));
    $this->weeklyStatsReporter->recordInstagramEvent($field, $value);
    $this->state->set($stateKey, time());
  }

  private function isConfigured(): bool {
    return getenv('ILDEPOSITO_ENV') === 'prod'
      && $this->facebookPageClient->isConfigured()
      && getenv('TELEGRAM_NOTIFICHE_BOT_TOKEN')
      && getenv('TELEGRAM_NOTIFICHE_CHAT_ID');
  }

  /** @param array<string, mixed> $value */
  private function format(string $field, array $value): string {
    $details = $field === 'comments' ? $this->commentDetails($value) : $this->mentionDetails($value);
    $username = $this->username($details, $value);
    $label = $field === 'comments' ? 'ha commentato' : 'ti ha menzionato';
    $text = '📸 Instagram: ' . ($username === '' ? 'un account' : '@' . $username) . ' ' . $label . '.';

    $body = $this->string($details['text'] ?? $details['caption'] ?? $value['text'] ?? '');
    if ($body !== '') {
      $text .= "\n“" . $body . '”';
    }
    $media = is_array($details['media'] ?? NULL) ? $details['media'] : [];
    $url = $this->string($details['permalink'] ?? $media['permalink'] ?? $value['permalink'] ?? '');
    if ($url !== '') {
      $text .= "\n" . $url;
    }
    return $text;
  }

  /** @param array<string, mixed> $value @return array<string, mixed> */
  private function commentDetails(array $value): array {
    $commentId = $this->string($value['id'] ?? $value['comment_id'] ?? '');
    return $commentId === '' ? [] : $this->graph($commentId, 'id,text,username,media{id,permalink}');
  }

  /** @param array<string, mixed> $value @return array<string, mixed> */
  private function mentionDetails(array $value): array {
    $mediaId = $this->string($value['media_id'] ?? $value['id'] ?? '');
    return $mediaId === '' ? [] : $this->graph($mediaId, 'id,caption,permalink,username');
  }

  /** @return array<string, mixed> */
  private function graph(string $id, string $fields): array {
    try {
      $response = $this->facebookPageClient->getAsPage($id, ['fields' => $fields]);
      $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      return is_array($payload) ? $payload : [];
    }
    catch (\Throwable) {
      // Un ritardo di propagazione Graph non deve far perdere l'interazione.
      return [];
    }
  }

  /** @param array<string, mixed> $details @param array<string, mixed> $value */
  private function username(array $details, array $value): string {
    $from = is_array($value['from'] ?? NULL) ? $value['from'] : [];
    return ltrim($this->string($details['username'] ?? $from['username'] ?? $value['username'] ?? ''), '@');
  }

  private function telegram(string $text): void {
    $this->httpClient->request('POST', 'https://api.telegram.org/bot' . getenv('TELEGRAM_NOTIFICHE_BOT_TOKEN') . '/sendMessage', [
      'form_params' => [
        'chat_id' => getenv('TELEGRAM_NOTIFICHE_CHAT_ID'),
        'text' => $text,
        'disable_web_page_preview' => TRUE,
      ],
      'timeout' => 10,
    ]);
  }

  private function string(mixed $value): string {
    return is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
  }

}
