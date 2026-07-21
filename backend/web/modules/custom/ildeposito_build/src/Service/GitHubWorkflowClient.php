<?php

declare(strict_types=1);

namespace Drupal\ildeposito_build\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

final class GitHubWorkflowClient {

  private const REPO = 'ilDeposito/ilDeposito.org';
  private const API_BASE = 'https://api.github.com';
  private const REQUEST_TIMEOUT = 30;
  private const TOKEN_CACHE_ID = 'ildeposito_build:github_installation_token';
  // Margine di sicurezza prima della scadenza reale dichiarata da GitHub
  // (TTL ~1h), per non rischiare di usare un token già scaduto tra una
  // richiesta e l'altra.
  private const TOKEN_EXPIRY_MARGIN = 60;

  // Stato di una run non ancora conclusa (campo "status" della run, distinto
  // da "conclusion" che esiste solo quando status === completed).
  private const ACTIVE_STATUSES = ['queued', 'in_progress', 'waiting'];

  // Workflow che condividono lo stesso concurrency group su GitHub Actions
  // (vedi "concurrency:" nei rispettivi .github/workflows/*.yml): avviarne
  // uno mentre un altro dello stesso gruppo è in corso lo cancella
  // (build-frontend-*, cancel-in-progress: true) o lo mette in coda dietro
  // (build-redirect-prod.yml, cancel-in-progress: false). Un workflow non
  // presente in questa mappa è considerato nel proprio gruppo isolato.
  private const CONCURRENCY_GROUPS = [
    'build-frontend-content-stage.yml' => ['build-frontend-content-stage.yml', 'build-frontend-stage.yml'],
    'build-frontend-stage.yml' => ['build-frontend-content-stage.yml', 'build-frontend-stage.yml'],
    'build-frontend-content-prod.yml' => ['build-frontend-content-prod.yml', 'build-frontend-prod.yml', 'build-redirect-prod.yml'],
    'build-frontend-prod.yml' => ['build-frontend-content-prod.yml', 'build-frontend-prod.yml', 'build-redirect-prod.yml'],
    'build-redirect-prod.yml' => ['build-frontend-content-prod.yml', 'build-frontend-prod.yml', 'build-redirect-prod.yml'],
  ];

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly CacheBackendInterface $cache,
  ) {}

  public function isConfigured(): bool {
    return (bool) Settings::get('ildeposito_build_github_app_id')
      && (bool) Settings::get('ildeposito_build_github_installation_id')
      && file_exists($this->getPrivateKeyPath());
  }

  public function getRepoUrl(): string {
    return 'https://github.com/' . self::REPO;
  }

  public function triggerWorkflow(string $workflow): bool {
    try {
      $this->apiRequest('POST', "/repos/" . self::REPO . "/actions/workflows/{$workflow}/dispatches", [
        'json' => ['ref' => 'main'],
      ]);
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Vero se una run attiva (o in coda) esiste per $workflow o per un
   * workflow che condivide il suo concurrency group (vedi CONCURRENCY_GROUPS)
   * — avviare $workflow in quel momento cancellerebbe o accoderebbe l'altra
   * run, quindi il pulsante di pubblicazione va disabilitato per entrambi.
   *
   * In caso di errore verso l'API GitHub non blocca l'utente (fail-open):
   * meglio un doppio trigger occasionale che un pulsante bloccato a vita da
   * un problema temporaneo di rete.
   */
  public function hasConflictingRunInProgress(string $workflow): bool {
    $group = self::CONCURRENCY_GROUPS[$workflow] ?? [$workflow];

    try {
      $response = $this->apiRequest('GET', "/repos/" . self::REPO . "/actions/runs", [
        'query' => [
          'per_page' => 20,
          'branch' => 'main',
        ],
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);

      foreach ($data['workflow_runs'] ?? [] as $run) {
        $file = basename((string) ($run['path'] ?? ''));
        if (in_array($file, $group, TRUE) && in_array($run['status'] ?? '', self::ACTIVE_STATUSES, TRUE)) {
          return TRUE;
        }
      }

      return FALSE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  private function apiRequest(string $method, string $path, array $options = []): ResponseInterface {
    $token = $this->getInstallationToken();

    $options['headers'] = array_merge($options['headers'] ?? [], [
      'Authorization' => "Bearer {$token}",
      'Accept' => 'application/vnd.github+json',
      'X-GitHub-Api-Version' => '2022-11-28',
    ]);
    $options['timeout'] = self::REQUEST_TIMEOUT;
    $options['connect_timeout'] = self::REQUEST_TIMEOUT;

    return $this->httpClient->request($method, self::API_BASE . $path, $options);
  }

  private function getInstallationToken(): string {
    $cached = $this->cache->get(self::TOKEN_CACHE_ID);
    if ($cached !== FALSE && is_string($cached->data) && $cached->data !== '') {
      return $cached->data;
    }

    return $this->createInstallationToken();
  }

  private function createInstallationToken(): string {
    $jwt = $this->generateJwt();
    $installationId = Settings::get('ildeposito_build_github_installation_id');

    $response = $this->httpClient->request('POST', self::API_BASE . "/app/installations/{$installationId}/access_tokens", [
      'headers' => [
        'Authorization' => "Bearer {$jwt}",
        'Accept' => 'application/vnd.github+json',
        'X-GitHub-Api-Version' => '2022-11-28',
      ],
      'timeout' => self::REQUEST_TIMEOUT,
      'connect_timeout' => self::REQUEST_TIMEOUT,
    ]);

    $data = json_decode((string) $response->getBody(), TRUE);
    if (!isset($data['token'])) {
      throw new \RuntimeException('Token di installazione non ricevuto da GitHub.');
    }

    $expiresAt = isset($data['expires_at']) ? strtotime((string) $data['expires_at']) : (time() + 3600);
    $this->cache->set(self::TOKEN_CACHE_ID, $data['token'], $expiresAt - self::TOKEN_EXPIRY_MARGIN);

    return $data['token'];
  }

  private function generateJwt(): string {
    $privateKey = file_get_contents($this->getPrivateKeyPath());
    if ($privateKey === FALSE) {
      throw new \RuntimeException('Impossibile leggere la chiave privata GitHub App.');
    }

    $header = $this->base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = $this->base64UrlEncode((string) json_encode([
      'iss' => Settings::get('ildeposito_build_github_app_id'),
      'iat' => time() - 60,
      'exp' => time() + 600,
    ]));

    $data = "{$header}.{$payload}";
    if (!openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
      throw new \RuntimeException('Impossibile firmare il JWT: chiave privata non valida.');
    }

    return "{$data}.{$this->base64UrlEncode($signature)}";
  }

  private function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

  private function getPrivateKeyPath(): string {
    return Settings::get(
      'ildeposito_build_github_private_key',
      DRUPAL_ROOT . '/../private/github-app.pem',
    );
  }

}
