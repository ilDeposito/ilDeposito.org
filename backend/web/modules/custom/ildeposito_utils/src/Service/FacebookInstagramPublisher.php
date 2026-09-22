<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;

/**
 * Replica su Instagram le foto native pubblicate dalla Pagina Facebook.
 */
final class FacebookInstagramPublisher {

  private const TABLE = 'ildeposito_utils_facebook_instagram';
  private const PROCESSING_LEASE = 600;
  private const MAX_CAPTION_LENGTH = 2200;

  /**
   * Intervallo di aspect ratio accettato dall'API di pubblicazione Instagram.
   *
   * @see https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/error-codes
   *   Errore 36003 / 2207009.
   */
  private const MIN_ASPECT_RATIO = 0.8;

  private const MAX_ASPECT_RATIO = 1.91;

  /**
   * Sottocartella pubblica che ospita i derivati con letterbox per Instagram.
   *
   * Il path /sites/default/files* ha il bypass Authelia su Caddy, quindi Meta
   * puo' scaricare il file quando crea il contenitore media.
   */
  private const FALLBACK_DIRECTORY = 'public://instagram';

  public const RESULT_DONE = 'done';
  public const RESULT_BUSY = 'busy';
  public const RESULT_IGNORED = 'ignored';

  public function __construct(
    private readonly FacebookPageClient $facebookPageClient,
    private readonly Connection $database,
    private readonly ClientInterface $httpClient,
    private readonly FileSystemInterface $fileSystem,
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
      $creationId = $this->createContainer($accountId, $facebookPostId, $imageUrl, $this->getCaption($post));
      if ($creationId === NULL) {
        // Foto deterministicamente non pubblicabile su Instagram (per esempio
        // aspect ratio fuori range anche dopo il letterbox): la saltiamo senza
        // bloccare Telegram e Mastodon, che il worker esegue subito dopo.
        $this->markIgnored($facebookPostId);
        return self::RESULT_IGNORED;
      }
      $this->database->update(self::TABLE)
        ->fields(['instagram_creation_id' => $creationId])
        ->condition('facebook_post_id', $facebookPostId)
        ->execute();
    }

    $container = $this->getMediaContainerStatus($creationId);
    if ($container['status_code'] === 'IN_PROGRESS') {
      // Meta scarica la foto dal suo URL in modo asincrono. Rilasciamo il
      // lease, così il prossimo giro della coda può riprovare senza attendere
      // i dieci minuti riservati ai processi realmente bloccati.
      $this->markPending($facebookPostId);
      return self::RESULT_BUSY;
    }
    if ($container['status_code'] !== 'FINISHED') {
      throw new \RuntimeException(sprintf(
        'Il contenitore media Instagram non è pubblicabile (%s): %s',
        $container['status_code'],
        $container['status'],
      ));
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

  /**
   * Crea il contenitore media, con fallback con letterbox se il ratio non va.
   *
   * Restituisce NULL quando la foto e' deterministicamente non pubblicabile
   * (il chiamante la marca ignorata cosi' Telegram e Mastodon proseguono);
   * rilancia invece gli errori transienti, che la coda ritentera'.
   */
  private function createContainer(string $accountId, string $facebookPostId, string $imageUrl, string $caption): ?string {
    try {
      return $this->createMediaContainer($accountId, $imageUrl, $caption);
    }
    catch (ClientException $exception) {
      if (!$this->isAspectRatioError($exception)) {
        throw $exception;
      }
    }

    $fallbackUrl = $this->paddedImageUrl($facebookPostId, $imageUrl);
    if ($fallbackUrl === NULL) {
      return NULL;
    }
    try {
      return $this->createMediaContainer($accountId, $fallbackUrl, $caption);
    }
    catch (ClientException $exception) {
      if (!$this->isAspectRatioError($exception)) {
        throw $exception;
      }
      return NULL;
    }
  }

  /**
   * Riconosce il rifiuto per aspect ratio (400 / 36003 / 2207009 di Meta).
   */
  private function isAspectRatioError(ClientException $exception): bool {
    $response = $exception->getResponse();
    if ($response === NULL) {
      return FALSE;
    }
    try {
      $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return str_contains(strtolower($exception->getMessage()), 'aspect ratio');
    }
    $error = is_array($payload) ? ($payload['error'] ?? NULL) : NULL;
    if (!is_array($error) || (int) ($error['code'] ?? 0) !== 36003) {
      return FALSE;
    }
    $subcode = (int) ($error['error_subcode'] ?? 0);
    return $subcode === 2207009 || str_contains(strtolower((string) ($error['message'] ?? '')), 'aspect ratio');
  }

  /**
   * Scarica la foto e ne pubblica un derivato con letterbox entro 1.91:1.
   *
   * Il contenuto non viene mai tagliato: si aggiungono solo bande nere fino
   * al ratio minimo accettato. Restituisce NULL quando l'immagine non e'
   * elaborabile; lancia RuntimeException per gli errori ambientali (rete,
   * disco, GD assente), che la coda deve ritentare.
   */
  private function paddedImageUrl(string $facebookPostId, string $imageUrl): ?string {
    try {
      $response = $this->httpClient->get($imageUrl, [
        'timeout' => 30,
        'connect_timeout' => 10,
      ]);
      $data = (string) $response->getBody();
    }
    catch (\Throwable $exception) {
      throw new \RuntimeException('Impossibile scaricare la foto Facebook per Instagram.', 0, $exception);
    }

    $size = @getimagesizefromstring($data);
    if ($size === FALSE || $size[0] <= 0 || $size[1] <= 0) {
      return NULL;
    }
    [$width, $height] = [$size[0], $size[1]];
    $ratio = $width / $height;
    if ($ratio >= self::MIN_ASPECT_RATIO && $ratio <= self::MAX_ASPECT_RATIO) {
      // Meta l'ha rifiutata ma il ratio misurato rientra: il padding non
      // aiuterebbe, evitiamo un ciclo di tentativi identici.
      return NULL;
    }
    if ($ratio > self::MAX_ASPECT_RATIO) {
      $targetWidth = $width;
      $targetHeight = (int) ceil($width / self::MAX_ASPECT_RATIO);
    }
    else {
      $targetWidth = (int) ceil($height * self::MIN_ASPECT_RATIO);
      $targetHeight = $height;
    }

    $padded = $this->letterbox($data, $width, $height, $targetWidth, $targetHeight);
    // prepareDirectory() riceve la directory per riferimento: serve una
    // variabile, non la costante di classe.
    $directory = self::FALLBACK_DIRECTORY;
    $this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );
    $filename = 'facebook-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($facebookPostId)) . '.jpg';
    // Il derivato resta su disco: Meta lo scarica in modo asincrono durante
    // l'elaborazione del contenitore, quindi non va rimosso dopo l'invio.
    $saved = $this->fileSystem->saveData($padded, $directory . '/' . $filename, FileSystemInterface::EXISTS_REPLACE);
    if ($saved === FALSE) {
      throw new \RuntimeException('Impossibile salvare il derivato Instagram con letterbox.');
    }
    $baseUrl = $this->getPublicBackendUrl();
    if ($baseUrl === '') {
      throw new \RuntimeException('URL pubblico del backend non configurato per il derivato Instagram.');
    }
    $path = '/sites/default/files/instagram/' . $filename;
    return $baseUrl . implode('/', array_map('rawurlencode', explode('/', $path)));
  }

  /**
   * Centra l'immagine su uno sfondo nero della dimensione indicata (JPEG).
   */
  private function letterbox(string $data, int $width, int $height, int $targetWidth, int $targetHeight): string {
    if (!function_exists('imagecreatefromstring')) {
      throw new \RuntimeException('GD non disponibile per il letterbox Instagram.');
    }
    $source = @imagecreatefromstring($data);
    if ($source === FALSE) {
      throw new \RuntimeException('Impossibile elaborare la foto Facebook per Instagram.');
    }
    try {
      $target = imagecreatetruecolor($targetWidth, $targetHeight);
      if ($target === FALSE) {
        throw new \RuntimeException('Impossibile creare il canvas per il letterbox Instagram.');
      }
      try {
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 0, 0, 0));
        imagecopy($target, $source, (int) (($targetWidth - $width) / 2), (int) (($targetHeight - $height) / 2), 0, 0, $width, $height);
        ob_start();
        $ok = imagejpeg($target, NULL, 90);
        $encoded = (string) ob_get_clean();
        if (!$ok || $encoded === '') {
          throw new \RuntimeException('Codifica JPEG del letterbox Instagram fallita.');
        }
        return $encoded;
      }
      finally {
        imagedestroy($target);
      }
    }
    finally {
      imagedestroy($source);
    }
  }

  /**
   * URL base pubblico del backend (stesso pattern della newsletter).
   *
   * Deterministico in ogni contesto (webhook, cron web, drush in crond), a
   * differenza di file_url_generator che senza request non ha host.
   */
  private function getPublicBackendUrl(): string {
    $url = trim((string) Settings::get('ildeposito_utils_public_backend_url', ''));
    if ($url !== '') {
      return rtrim($url, '/');
    }
    $ddev = getenv('DDEV_PRIMARY_URL');
    return $ddev === FALSE ? '' : rtrim($ddev, '/');
  }

  /** @return array{status_code: string, status: string} */
  private function getMediaContainerStatus(string $creationId): array {
    $response = $this->facebookPageClient->getAsPage($creationId, [
      'fields' => 'status_code,status',
    ]);
    try {
      $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException('Instagram ha restituito lo stato del contenitore media in un formato non JSON.', 0, $exception);
    }
    $statusCode = is_array($payload) ? ($payload['status_code'] ?? NULL) : NULL;
    $status = is_array($payload) ? ($payload['status'] ?? NULL) : NULL;
    if (!is_string($statusCode) || $statusCode === '') {
      throw new \RuntimeException('Instagram non ha restituito lo stato del contenitore media.');
    }
    return [
      'status_code' => $statusCode,
      'status' => is_string($status) && $status !== '' ? $status : 'nessun dettaglio fornito',
    ];
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

  private function markPending(string $facebookPostId): void {
    $this->database->update(self::TABLE)
      ->fields(['status' => 'pending', 'processing_started' => NULL])
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
