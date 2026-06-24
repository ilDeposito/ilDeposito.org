<?php

declare(strict_types=1);

namespace Drupal\ildeposito_build\Service;

use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

final class GitHubWorkflowClient {

  private const REPO = 'ilDeposito/ilDeposito.org';
  private const API_BASE = 'https://api.github.com';
  private const REQUEST_TIMEOUT = 30;

  public function __construct(
    private readonly ClientInterface $httpClient,
  ) {}

  public function isConfigured(): bool {
    return (bool) Settings::get('ildeposito_build_github_app_id')
      && (bool) Settings::get('ildeposito_build_github_installation_id')
      && file_exists($this->getPrivateKeyPath());
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

  public function findWorkflowRun(string $workflow, int $triggeredAfter): ?array {
    try {
      $response = $this->apiRequest('GET', "/repos/" . self::REPO . "/actions/workflows/{$workflow}/runs", [
        'query' => [
          'per_page' => 5,
          'branch' => 'main',
          'event' => 'workflow_dispatch',
        ],
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);
      $threshold = $triggeredAfter - 60;

      foreach ($data['workflow_runs'] ?? [] as $run) {
        if (!isset($run['id'], $run['status'], $run['created_at'])) {
          continue;
        }
        $createdAt = strtotime($run['created_at']);
        if ($createdAt >= $threshold && $run['status'] !== 'completed') {
          return [
            'id' => (int) $run['id'],
            'status' => $run['status'],
          ];
        }
      }

      return NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  public function getWorkflowRun(int $runId): ?array {
    try {
      $response = $this->apiRequest('GET', "/repos/" . self::REPO . "/actions/runs/{$runId}");
      $data = json_decode((string) $response->getBody(), TRUE);

      if (!isset($data['id'], $data['status'])) {
        return NULL;
      }

      return [
        'id' => (int) $data['id'],
        'status' => $data['status'],
        'conclusion' => $data['conclusion'] ?? NULL,
      ];
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  private function apiRequest(string $method, string $path, array $options = []): ResponseInterface {
    $token = $this->createInstallationToken();

    $options['headers'] = array_merge($options['headers'] ?? [], [
      'Authorization' => "Bearer {$token}",
      'Accept' => 'application/vnd.github+json',
      'X-GitHub-Api-Version' => '2022-11-28',
    ]);
    $options['timeout'] = self::REQUEST_TIMEOUT;
    $options['connect_timeout'] = self::REQUEST_TIMEOUT;

    return $this->httpClient->request($method, self::API_BASE . $path, $options);
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
