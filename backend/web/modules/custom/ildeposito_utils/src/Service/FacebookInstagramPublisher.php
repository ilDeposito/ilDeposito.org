<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Service;

use Drupal\Core\Database\Connection;

/**
 * Replica su Instagram le foto native pubblicate dalla Pagina Facebook.
 */
final class FacebookInstagramPublisher {

  private const TABLE = 'ildeposito_utils_facebook_instagram';
  private const PROCESSING_LEASE = 600;
  private const MAX_CAPTION_LENGTH = 2200;

  public const RESULT_DONE = 'done';
  public const RESULT_BUSY = 'busy';
  public const RESULT_IGNORED = 'ignored';

  public function __construct(
    private readonly FacebookPageClient $facebookPageClient,
    private readonly Connection $database,
  ) {}

  public function isConfigured(): bool {
    return $this->facebookPageClient->isConfigured();
  }

  /** Publishes one native Facebook photo at most once. */
  public function publish(string $facebookPostId): string {
    if (!$this->isConfigured()) {
      throw new \LogicException('Replica Facebook -> Instagram non configurata.');
    }
    if (!$this->claimRecord($facebookPostId)) {
      return match ($this->getRecordStatus($facebookPostId)) {
        'sent' => self::RESULT_DONE,
        'ignored' => self::RESULT_IGNORED,
        default => self::RESULT_BUSY,
      };
    }

    $post = $this->getFacebookPost($facebookPostId);
    if (!$this->isOfficialPost($post) || ($imageUrl = $this->getNativePhotoUrl($post)) === NULL) {
      $this->markIgnored($facebookPostId);
      return self::RESULT_IGNORED;
    }

    $accountId = $this->getInstagramAccountId();
    $creationId = $this->getCreationId($facebookPostId);
    if ($creationId === NULL) {
      $creationId = $this->createMediaContainer($accountId, $imageUrl, $this->getCaption($post));
      $this->database->update(self::TABLE)
        ->fields(['instagram_creation_id' => $creationId])
        ->condition('facebook_post_id', $facebookPostId)
        ->execute();
    }

    $mediaId = $this->publishMediaContainer($accountId, $creationId);
    $this->database->update(self::TABLE)
      ->fields(['status' => 'sent', 'instagram_media_id' => $mediaId, 'sent' => time()])
      ->condition('facebook_post_id', $facebookPostId)
      ->execute();
    return self::RESULT_DONE;
  }

  /** @return array<string, mixed> */
  private function getFacebookPost(string $facebookPostId): array {
    $response = $this->facebookPageClient->getAsPage($facebookPostId, [
      'fields' => 'id,from{id},message,attachments{media_type,media{image},subattachments}',
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

  /** @param array<string, mixed> $post */
  private function isOfficialPost(array $post): bool {
    $author = $post['from'] ?? [];
    return is_array($author) && (string) ($author['id'] ?? '') === $this->facebookPageClient->getPageId();
  }

  /** @param array<string, mixed> $post */
  private function getNativePhotoUrl(array $post): ?string {
    $attachments = $post['attachments']['data'] ?? [];
    if (!is_array($attachments) || count($attachments) !== 1) {
      return NULL;
    }
    $attachment = $attachments[0] ?? NULL;
    if (!is_array($attachment)
      || ($attachment['media_type'] ?? NULL) !== 'photo'
      || !empty($attachment['subattachments'])) {
      return NULL;
    }
    $url = $attachment['media']['image']['src'] ?? NULL;
    return is_string($url) && $url !== '' ? $url : NULL;
  }

  /** Restituisce l'account Instagram professionale collegato alla Pagina. */
  public function getInstagramAccountId(): string {
    $response = $this->facebookPageClient->getAsPage($this->facebookPageClient->getPageId(), [
      'fields' => 'instagram_business_account{id}',
    ]);
    try {
      $page = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException('Facebook ha restituito una Pagina non JSON.', 0, $exception);
    }
    $accountId = is_array($page) ? ($page['instagram_business_account']['id'] ?? NULL) : NULL;
    if (!is_string($accountId) || $accountId === '') {
      throw new \RuntimeException('Alla Pagina Facebook non risulta collegato un account Instagram professionale.');
    }
    return $accountId;
  }

  private function createMediaContainer(string $accountId, string $imageUrl, string $caption): string {
    $response = $this->facebookPageClient->post($accountId . '/media', [
      'image_url' => $imageUrl,
      'caption' => $caption,
    ]);
    return $this->responseId($response->getBody(), 'contenitore media Instagram');
  }

  private function publishMediaContainer(string $accountId, string $creationId): string {
    $response = $this->facebookPageClient->post($accountId . '/media_publish', ['creation_id' => $creationId]);
    return $this->responseId($response->getBody(), 'pubblicazione Instagram');
  }

  private function responseId(mixed $body, string $operation): string {
    try {
      $payload = json_decode((string) $body, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException(sprintf('Instagram ha restituito una risposta non JSON durante %s.', $operation), 0, $exception);
    }
    $id = is_array($payload) ? ($payload['id'] ?? NULL) : NULL;
    if (!is_string($id) || $id === '') {
      throw new \RuntimeException(sprintf('Instagram non ha restituito un ID durante %s.', $operation));
    }
    return $id;
  }

  /** @param array<string, mixed> $post */
  private function getCaption(array $post): string {
    $caption = trim((string) ($post['message'] ?? ''));
    return mb_strlen($caption) <= self::MAX_CAPTION_LENGTH
      ? $caption
      : rtrim(mb_substr($caption, 0, self::MAX_CAPTION_LENGTH - 1)) . '…';
  }

  private function claimRecord(string $facebookPostId): bool {
    $this->ensureRecord($facebookPostId);
    $now = time();
    $stale = $this->database->condition('AND')
      ->condition('status', 'publishing')
      ->condition('processing_started', $now - self::PROCESSING_LEASE, '<=');
    $available = $this->database->condition('OR')
      ->condition('status', 'pending')
      ->condition($stale);
    return (bool) $this->database->update(self::TABLE)
      ->fields(['status' => 'publishing', 'processing_started' => $now])
      ->condition('facebook_post_id', $facebookPostId)
      ->condition($available)
      ->execute();
  }

  private function getRecordStatus(string $facebookPostId): string {
    return (string) $this->database->select(self::TABLE, 'f')
      ->fields('f', ['status'])
      ->condition('facebook_post_id', $facebookPostId)
      ->execute()
      ->fetchField();
  }

  private function getCreationId(string $facebookPostId): ?string {
    $creationId = $this->database->select(self::TABLE, 'f')
      ->fields('f', ['instagram_creation_id'])
      ->condition('facebook_post_id', $facebookPostId)
      ->execute()
      ->fetchField();
    return is_string($creationId) && $creationId !== '' ? $creationId : NULL;
  }

  private function markIgnored(string $facebookPostId): void {
    $this->database->update(self::TABLE)
      ->fields(['status' => 'ignored', 'sent' => time()])
      ->condition('facebook_post_id', $facebookPostId)
      ->execute();
  }

  private function ensureRecord(string $facebookPostId): void {
    $record = $this->database->select(self::TABLE, 'f')
      ->fields('f', ['facebook_post_id'])
      ->condition('facebook_post_id', $facebookPostId)
      ->execute()
      ->fetchField();
    if ($record !== FALSE) {
      return;
    }
    try {
      $this->database->insert(self::TABLE)
        ->fields(['facebook_post_id' => $facebookPostId, 'status' => 'pending', 'created' => time()])
        ->execute();
    }
    catch (\Exception) {
      // Un secondo webhook puo' vincere la race: rilegge il record.
    }
  }

}
