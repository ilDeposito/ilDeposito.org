<?php

declare(strict_types=1);

function base64UrlEncode(string $value): string {
  return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

$appId = getenv('GITHUB_APP_ID') ?: '';
$installationId = getenv('GITHUB_APP_INSTALLATION_ID') ?: '';
$keyPath = '/var/www/html/private/github-app.pem';
$privateKey = is_readable($keyPath) ? file_get_contents($keyPath) : FALSE;

if ($appId === '' || $installationId === '' || $privateKey === FALSE) {
  fwrite(STDERR, "GitHub App non configurata nel container PHP.\n");
  exit(1);
}

$header = base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
$payload = base64UrlEncode((string) json_encode([
  'iss' => $appId,
  'iat' => time() - 60,
  'exp' => time() + 600,
], JSON_THROW_ON_ERROR));
$unsignedToken = $header . '.' . $payload;

if (!openssl_sign($unsignedToken, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
  fwrite(STDERR, "Impossibile firmare il JWT della GitHub App.\n");
  exit(1);
}

$curl = curl_init('https://api.github.com/app/installations/' . rawurlencode($installationId) . '/access_tokens');
curl_setopt_array($curl, [
  CURLOPT_POST => TRUE,
  CURLOPT_POSTFIELDS => '{}',
  CURLOPT_RETURNTRANSFER => TRUE,
  CURLOPT_CONNECTTIMEOUT => 15,
  CURLOPT_TIMEOUT => 30,
  CURLOPT_HTTPHEADER => [
    'Accept: application/vnd.github+json',
    'Authorization: Bearer ' . $unsignedToken . '.' . base64UrlEncode($signature),
    'User-Agent: ilDeposito-backend-update',
    'X-GitHub-Api-Version: 2022-11-28',
  ],
]);
$response = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$error = curl_error($curl);
// Da PHP 8 il handle cURL viene rilasciato automaticamente; curl_close() e'
// deprecato in PHP 8.5 e il suo warning non deve contaminare lo stdout, che
// questo helper riserva esclusivamente al token di installazione.
unset($curl);

if (!is_string($response) || $status < 200 || $status >= 300) {
  fwrite(STDERR, "Token GitHub App non ottenuto (HTTP {$status}): {$error}\n");
  exit(1);
}

$data = json_decode($response, TRUE, 512, JSON_THROW_ON_ERROR);
if (!isset($data['token']) || !is_string($data['token'])) {
  fwrite(STDERR, "Risposta GitHub App priva di token.\n");
  exit(1);
}

print $data['token'];
