<?php

declare(strict_types=1);

namespace Drupal\ildeposito_stats\Service;

use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;

final class UmamiClient {

  private const REQUEST_TIMEOUT = 15;

  public function __construct(
    private readonly ClientInterface $httpClient,
  ) {}

  public function isConfigured(): bool {
    return (bool) Settings::get('ildeposito_stats_umami_api_url')
      && (bool) Settings::get('ildeposito_stats_umami_username')
      && (bool) Settings::get('ildeposito_stats_umami_password')
      && (bool) Settings::get('ildeposito_stats_umami_website_id');
  }

  /**
   * Visite raggruppate per URL nelle ultime $hours ore, ordinate per più viste.
   *
   * @return array<int, array{url: string, visite: int}>
   */
  public function getPageviewsByUrl(int $hours): array {
    $endAt = (int) (microtime(TRUE) * 1000);
    return $this->getPageviewsByUrlBetween($endAt - $hours * 3600 * 1000, $endAt);
  }

  /**
   * Visite raggruppate per URL tra due timestamp (ms epoch), ordinate per
   * più viste. Finestra esplicita: serve sia per finestre fisse (6h/24h/
   * settimana) sia per il delta a watermark variabile (dall'ultimo sync).
   *
   * @return array<int, array{url: string, visite: int}>
   */
  public function getPageviewsByUrlBetween(int $startAt, int $endAt): array {
    $token = $this->login();

    $response = $this->httpClient->request('GET', $this->buildUrl('/api/websites/' . $this->getWebsiteId() . '/metrics'), [
      'headers' => [
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json',
      ],
      'query' => [
        'startAt' => $startAt,
        'endAt' => $endAt,
        'type' => 'path',
      ],
      'timeout' => self::REQUEST_TIMEOUT,
      'connect_timeout' => self::REQUEST_TIMEOUT,
    ]);

    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data)) {
      throw new \RuntimeException('Risposta Umami non valida (metrics per URL).');
    }

    $rows = array_map(
      static fn(array $row): array => ['url' => (string) $row['x'], 'visite' => (int) $row['y']],
      $data,
    );

    usort($rows, static fn(array $a, array $b): int => $b['visite'] <=> $a['visite']);

    return $rows;
  }

  /**
   * Self-hosted Umami non supporta API key (solo Umami Cloud): login a ogni
   * chiamata con utente/password ed evita di dover gestire la scadenza del
   * token tra un'esecuzione schedulata e l'altra.
   */
  private function login(): string {
    $response = $this->httpClient->request('POST', $this->buildUrl('/api/auth/login'), [
      'json' => [
        'username' => (string) Settings::get('ildeposito_stats_umami_username'),
        'password' => (string) Settings::get('ildeposito_stats_umami_password'),
      ],
      'headers' => ['Accept' => 'application/json'],
      'timeout' => self::REQUEST_TIMEOUT,
      'connect_timeout' => self::REQUEST_TIMEOUT,
    ]);

    $data = json_decode((string) $response->getBody(), TRUE);
    if (!isset($data['token'])) {
      throw new \RuntimeException('Login Umami fallito: token non ricevuto.');
    }

    return (string) $data['token'];
  }

  private function getWebsiteId(): string {
    return (string) Settings::get('ildeposito_stats_umami_website_id');
  }

  private function buildUrl(string $path): string {
    return rtrim((string) Settings::get('ildeposito_stats_umami_api_url'), '/') . $path;
  }

}
