<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;

/**
 * Converte un post della Pagina Facebook in un messaggio del canale Telegram.
 */
final class FacebookTelegramPublisher {

  private const TABLE = 'ildeposito_utils_facebook_telegram';

  public function __construct(
    private readonly FacebookPageClient $facebookPageClient,
    private readonly ClientInterface $httpClient,
    private readonly Connection $database,
  ) {}

  /**
   * Indica se le due estremita' della replica sono configurate.
   */
  public function isConfigured(): bool {
    return $this->facebookPageClient->isConfigured()
      && $this->getTelegramToken() !== ''
      && $this->getTelegramChatId() !== '';
  }

  /**
   * Pubblica una sola volta un post identificato dalla Graph API.
   */
  public function publish(string $facebookPostId): void {
    if (!$this->isConfigured()) {
      throw new \LogicException('Replica Facebook -> Telegram non configurata.');
    }

    $row = $this->loadOrCreateRecord($facebookPostId);
    if ($row !== NULL && $row->status === 'sent') {
      return;
    }

    $post = $this->getFacebookPost($facebookPostId);
    $messageId = $this->sendToTelegram($post);
    $this->database->update(self::TABLE)
      ->fields(['status' => 'sent', 'telegram_message_id' => $messageId, 'sent' => time()])
      ->condition('facebook_post_id', $facebookPostId)
      ->execute();
  }

  /**
   * @return array<string, mixed>
   */
  private function getFacebookPost(string $facebookPostId): array {
    $response = $this->facebookPageClient->getAsPage($facebookPostId, [
      'fields' => 'id,message,created_time,permalink_url,full_picture,attachments{media_type,url,unshimmed_url,target,media,subattachments}',
    ]);
    try {
      $post = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException('Facebook ha restituito un post non JSON.', 0, $exception);
    }
    if (!is_array($post) || (string) ($post['id'] ?? '') !== $facebookPostId) {
      throw new \RuntimeException('Facebook non ha restituito il post richiesto.');
    }
    return $post;
  }

  /**
   * @param array<string, mixed> $post
   */
  private function sendToTelegram(array $post): int {
    $text = trim((string) ($post['message'] ?? ''));
    $urls = $this->collectUrls($post['attachments'] ?? []);
    $eventUrl = $this->findEventUrl(array_merge($urls, $this->urlsInText($text)));

    // I post automatici della Storia Cantata rimandano alla scheda evento:
    // sul canale e' piu' utile la preview OpenGraph del sito della foto FB.
    if ($eventUrl !== NULL) {
      return $this->sendMessage($this->appendUrl($text, $eventUrl));
    }

    $photoUrl = trim((string) ($post['full_picture'] ?? ''));
    if ($photoUrl !== '') {
      return $this->sendPhoto($photoUrl, $text);
    }

    $linkUrl = $urls[0] ?? NULL;
    if ($linkUrl !== NULL) {
      $text = $this->appendUrl($text, $linkUrl);
    }
    if ($text === '') {
      $text = trim((string) ($post['permalink_url'] ?? ''));
    }
    if ($text === '') {
      throw new \RuntimeException('Il post Facebook non contiene testo, link o foto pubblicabili.');
    }
    return $this->sendMessage($text);
  }

  private function sendPhoto(string $photoUrl, string $caption): int {
    // Scarichiamo e ricarichiamo l'immagine: sendPhoto via URL ha un limite di
    // 5 MB, mentre l'upload multipart arriva a 10 MB ed e' piu' affidabile con
    // URL CDN Facebook firmati o temporanei.
    $image = $this->httpClient->get($photoUrl, [
      'stream' => TRUE,
      'timeout' => 30,
      'connect_timeout' => 10,
    ]);
    $contentLength = (int) $image->getHeaderLine('Content-Length');
    if ($contentLength > 10 * 1024 * 1024) {
      throw new \RuntimeException('La foto Facebook supera il limite Telegram di 10 MB.');
    }

    if (mb_strlen($caption) > 1024) {
      $messageId = $this->telegramPhotoRequest($image->getBody(), []);
      $this->sendMessage($caption);
      return $messageId;
    }

    $parameters = [];
    if ($caption !== '') {
      $parameters['caption'] = $caption;
    }
    return $this->telegramPhotoRequest($image->getBody(), $parameters);
  }

  private function sendMessage(string $text): int {
    $chunks = $this->splitText($text, 4096);
    $messageId = 0;
    foreach ($chunks as $chunk) {
      $messageId = $this->telegramRequest('sendMessage', ['text' => $chunk]);
    }
    return $messageId;
  }

  /**
   * @param array<string, string> $parameters
   */
  private function telegramRequest(string $method, array $parameters): int {
    $response = $this->httpClient->post('https://api.telegram.org/bot' . $this->getTelegramToken() . '/' . $method, [
      'form_params' => array_merge(['chat_id' => $this->getTelegramChatId()], $parameters),
      'timeout' => 20,
      'connect_timeout' => 10,
    ]);
    return $this->telegramMessageId((string) $response->getBody());
  }

  /**
   * @param array<string, string> $parameters
   */
  private function telegramPhotoRequest(mixed $image, array $parameters): int {
    $multipart = [
      ['name' => 'chat_id', 'contents' => $this->getTelegramChatId()],
      ['name' => 'photo', 'contents' => $image, 'filename' => 'facebook-photo.jpg'],
    ];
    foreach ($parameters as $name => $value) {
      $multipart[] = ['name' => $name, 'contents' => $value];
    }
    $response = $this->httpClient->post('https://api.telegram.org/bot' . $this->getTelegramToken() . '/sendPhoto', [
      'multipart' => $multipart,
      'timeout' => 30,
      'connect_timeout' => 10,
    ]);
    return $this->telegramMessageId((string) $response->getBody());
  }

  private function telegramMessageId(string $body): int {
    try {
      $payload = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException('Telegram ha restituito una risposta non JSON.', 0, $exception);
    }
    $messageId = is_array($payload) ? ($payload['result']['message_id'] ?? NULL) : NULL;
    if (!is_int($messageId)) {
      throw new \RuntimeException('Telegram non ha confermato la pubblicazione.');
    }
    return $messageId;
  }

  /**
   * @param mixed $attachments
   * @return array<int, string>
   */
  private function collectUrls(mixed $attachments): array {
    $urls = [];
    $walk = static function (mixed $value) use (&$walk, &$urls): void {
      if (!is_array($value)) {
        return;
      }
      foreach (['unshimmed_url', 'url'] as $key) {
        if (isset($value[$key]) && is_string($value[$key]) && filter_var($value[$key], FILTER_VALIDATE_URL)) {
          $urls[] = $value[$key];
        }
      }
      foreach ($value as $child) {
        if (is_array($child)) {
          $walk($child);
        }
      }
    };
    $walk($attachments);
    return array_values(array_unique($urls));
  }

  /**
   * @return array<int, string>
   */
  private function urlsInText(string $text): array {
    preg_match_all('~https?://[^\s<>]+~u', $text, $matches);
    return array_values(array_filter($matches[0] ?? [], static fn (string $url): bool => filter_var($url, FILTER_VALIDATE_URL) !== FALSE));
  }

  /**
   * @param array<int, string> $urls
   */
  private function findEventUrl(array $urls): ?string {
    foreach ($urls as $url) {
      $parts = parse_url($url);
      if (is_array($parts)
        && in_array($parts['host'] ?? '', ['ildeposito.org', 'www.ildeposito.org'], TRUE)
        && str_starts_with($parts['path'] ?? '', '/eventi/')) {
        return $url;
      }
    }
    return NULL;
  }

  private function appendUrl(string $text, string $url): string {
    return str_contains($text, $url) ? $text : trim($text . "\n\n" . $url);
  }

  /**
   * @return array<int, string>
   */
  private function splitText(string $text, int $limit): array {
    if (mb_strlen($text) <= $limit) {
      return [$text];
    }
    $chunks = [];
    while ($text !== '') {
      $chunk = mb_strcut($text, 0, $limit, 'UTF-8');
      $chunks[] = $chunk;
      $text = mb_substr($text, mb_strlen($chunk), NULL, 'UTF-8');
    }
    return $chunks;
  }

  private function loadOrCreateRecord(string $facebookPostId): ?object {
    $record = $this->database->select(self::TABLE, 'f')
      ->fields('f')
      ->condition('facebook_post_id', $facebookPostId)
      ->execute()
      ->fetchObject();
    if ($record !== FALSE) {
      return $record;
    }
    try {
      $this->database->insert(self::TABLE)
        ->fields([
          'facebook_post_id' => $facebookPostId,
          'status' => 'pending',
          'created' => time(),
        ])
        ->execute();
    }
    catch (\Exception) {
      // Un secondo webhook puo' vincere la race: rilegge il record.
    }
    return $this->database->select(self::TABLE, 'f')
      ->fields('f')
      ->condition('facebook_post_id', $facebookPostId)
      ->execute()
      ->fetchObject() ?: NULL;
  }

  private function getTelegramToken(): string {
    return trim((string) Settings::get('ildeposito_utils_telegram_channel_bot_token', ''));
  }

  private function getTelegramChatId(): string {
    return trim((string) Settings::get('ildeposito_utils_telegram_channel_chat_id', ''));
  }

}
