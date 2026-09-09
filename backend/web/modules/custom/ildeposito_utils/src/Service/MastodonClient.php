<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Service;

use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Client autenticato per l'API dell'account Mastodon.
 *
 * Le credenziali sono lette esclusivamente dalle variabili d'ambiente: il
 * token non viene mai incluso in messaggi di errore o log.
 */
final class MastodonClient {

  private ?int $statusCharacterLimit = NULL;

  public function __construct(
    private readonly ClientInterface $httpClient,
  ) {}

  /**
   * Indica se sono presenti le credenziali minime per parlare con Mastodon.
   */
  public function isConfigured(): bool {
    return $this->getConfiguredBaseUrl() !== '' && $this->getAccessToken() !== '';
  }

  /**
   * Verifica il token senza creare o modificare alcuna risorsa Mastodon.
   *
   * @return array<string, mixed>
   *   Il profilo dell'account restituito da Mastodon.
   */
  public function verifyCredentials(): array {
    $response = $this->request('GET', '/api/v1/accounts/verify_credentials');

    try {
      $account = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException('Mastodon ha restituito una risposta non valida.', previous: $exception);
    }

    if (!is_array($account) || !is_string($account['acct'] ?? NULL) || $account['acct'] === '') {
      throw new \RuntimeException('Mastodon ha restituito un profilo senza identificativo account.');
    }

    return $account;
  }

  /** Invia una richiesta autenticata all'istanza configurata. */
  public function request(string $method, string $path, array $options = []): ResponseInterface {
    $options['headers'] = array_merge($options['headers'] ?? [], [
      'Accept' => 'application/json',
      'Authorization' => 'Bearer ' . $this->getAccessToken(),
    ]);
    $options['connect_timeout'] ??= 10;
    $options['timeout'] ??= 30;
    return $this->httpClient->request($method, $this->buildUrl($path), $options);
  }

  /** Restituisce il limite caratteri dichiarato dall'istanza, con fallback. */
  public function statusCharacterLimit(): int {
    if ($this->statusCharacterLimit !== NULL) return $this->statusCharacterLimit;
    try {
      $payload = json_decode((string) $this->request('GET', '/api/v2/instance')->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      $limit = $payload['configuration']['statuses']['max_characters'] ?? NULL;
      if (is_int($limit) && $limit > 0) return $this->statusCharacterLimit = $limit;
    }
    catch (\Throwable) {}
    return $this->statusCharacterLimit = 500;
  }

  /**
   * Restituisce l'URL dell'istanza, validandolo prima di una richiesta.
   */
  private function getBaseUrl(): string {
    $base_url = $this->getConfiguredBaseUrl();
    $parts = parse_url($base_url);
    if ($base_url === '' || $parts === FALSE || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
      throw new \LogicException('Mastodon non configurato correttamente: MASTODON_BASE_URL deve essere un URL HTTPS dell’istanza.');
    }

    return $base_url;
  }

  private function buildUrl(string $path): string {
    return $this->getBaseUrl() . '/' . ltrim($path, '/');
  }

  private function getConfiguredBaseUrl(): string {
    return rtrim(trim((string) Settings::get('ildeposito_utils_mastodon_base_url', '')), '/');
  }

  private function getAccessToken(): string {
    return trim((string) Settings::get('ildeposito_utils_mastodon_access_token', ''));
  }

}
