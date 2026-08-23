<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Service;

use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Client autenticato per le chiamate Graph API della Pagina Facebook.
 *
 * Usa il token non in scadenza del System User, assegnato alla Pagina e
 * all'app Meta del Business Portfolio. Il token non viene mai restituito né
 * incluso nelle eccezioni: deve vivere soltanto nelle variabili d'ambiente.
 */
final class FacebookPageClient {

  private const DEFAULT_API_VERSION = 'v26.0';

  private const GRAPH_API_BASE_URL = 'https://graph.facebook.com';

  /**
   * Token della Pagina derivato, per la durata di questa esecuzione PHP.
   *
   * Il token del System User autentica il servizio; gli edge che pubblicano
   * contenuti richiedono però che l'azione sia eseguita come Pagina.
   */
  private ?string $pageAccessToken = NULL;

  public function __construct(
    private readonly ClientInterface $httpClient,
  ) {}

  /**
   * Indica se sono presenti le credenziali minime per usare la Pagina.
   */
  public function isConfigured(): bool {
    return $this->getConfiguredPageId() !== '' && $this->getAccessToken() !== '';
  }

  /**
   * Restituisce l'ID della Pagina configurata.
   */
  public function getPageId(): string {
    $page_id = $this->getConfiguredPageId();
    if ($page_id === '') {
      throw new \LogicException('Facebook Page API non configurata: manca FB_PAGE_ID.');
    }

    return $page_id;
  }

  /**
   * Invia una richiesta GET autenticata alla Graph API.
   *
   * @param array<string, scalar|array<scalar>> $query
   *   Parametri della richiesta.
   */
  public function get(string $path, array $query = []): ResponseInterface {
    return $this->httpClient->request('GET', $this->buildUrl($path), [
      'query' => array_merge($query, $this->authenticationParameters()),
    ]);
  }

  /**
   * Invia una richiesta POST autenticata alla Graph API.
   *
   * @param array<string, scalar|array<scalar>> $form_parameters
   *   Parametri form della richiesta.
   */
  public function post(string $path, array $form_parameters = []): ResponseInterface {
    return $this->httpClient->request('POST', $this->buildUrl($path), [
      'form_params' => array_merge($form_parameters, $this->pageAuthenticationParameters()),
    ]);
  }

  /**
   * Invia una richiesta POST multipart autenticata alla Graph API.
   *
   * Serve, fra l'altro, a caricare il file binario di una foto sul relativo
   * edge della Pagina.
   *
   * @param array<int, array{name: string, contents: mixed, filename?: string}> $multipart
   *   Parti multipart da inviare.
   */
  public function postMultipart(string $path, array $multipart): ResponseInterface {
    foreach ($this->pageAuthenticationParameters() as $name => $contents) {
      $multipart[] = [
        'name' => $name,
        'contents' => $contents,
      ];
    }

    return $this->httpClient->request('POST', $this->buildUrl($path), [
      'multipart' => $multipart,
    ]);
  }

  /**
   * Invia una richiesta POST a un edge della Pagina configurata.
   *
   * Esempi di edge: "photos", "feed", "comments".
   *
   * @param array<string, scalar|array<scalar>> $form_parameters
   *   Parametri form della richiesta.
   */
  public function postToPage(string $edge, array $form_parameters = []): ResponseInterface {
    return $this->post(sprintf('%s/%s', $this->getPageId(), ltrim($edge, '/')), $form_parameters);
  }

  /**
   * Costruisce l'URL versionato e impedisce URL esterni accidentali.
   */
  private function buildUrl(string $path): string {
    return sprintf(
      '%s/%s/%s',
      self::GRAPH_API_BASE_URL,
      $this->getApiVersion(),
      ltrim($path, '/'),
    );
  }

  /**
   * Parametri di autenticazione comuni a ogni richiesta.
   *
   * appsecret_proof è opzionale, ma consigliato quando FB_APP_SECRET è
   * impostato: impedisce l'uso del token da parte di chi lo intercettasse.
   *
   * @return array<string, string>
   *   Parametri da passare alla Graph API.
   */
  private function authenticationParameters(): array {
    return $this->authenticationParametersForToken($this->getAccessToken());
  }

  /**
   * Parametri di autenticazione per le operazioni eseguite come Pagina.
   *
   * Graph API consente al token non in scadenza del System User di leggere il
   * token della risorsa Pagina assegnata. Le pubblicazioni (foto, post e
   * commenti) devono invece usare quest'ultimo: con il token del System User
   * Facebook interpreta la richiesta come una pubblicazione da profilo e
   * restituisce il fuorviante errore sul vecchio publish_actions.
   *
   * @return array<string, string>
   *   Parametri da passare alla Graph API.
   */
  private function pageAuthenticationParameters(): array {
    return $this->authenticationParametersForToken($this->getPageAccessToken());
  }

  /**
   * Restituisce il token della Pagina assegnata al System User.
   */
  private function getPageAccessToken(): string {
    if ($this->pageAccessToken !== NULL) {
      return $this->pageAccessToken;
    }

    $response = $this->httpClient->request('GET', $this->buildUrl($this->getPageId()), [
      'query' => array_merge([
        'fields' => 'access_token',
      ], $this->authenticationParameters()),
    ]);

    try {
      $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException('Facebook ha restituito una risposta non JSON durante il recupero del token Pagina.', 0, $exception);
    }

    $page_access_token = is_array($payload) ? ($payload['access_token'] ?? NULL) : NULL;
    if (!is_string($page_access_token) || $page_access_token === '') {
      throw new \RuntimeException('Facebook non ha restituito il token della Pagina. Verifica l’assegnazione della Pagina all’utente di sistema.');
    }

    $this->pageAccessToken = $page_access_token;
    return $this->pageAccessToken;
  }

  /**
   * Costruisce i parametri di autenticazione per un token Graph API.
   *
   * @return array<string, string>
   *   Parametri da passare alla Graph API.
   */
  private function authenticationParametersForToken(string $access_token): array {
    if ($access_token === '') {
      throw new \LogicException('Facebook Page API non configurata: manca FB_SYSTEM_USER_TOKEN.');
    }

    $parameters = ['access_token' => $access_token];
    $app_secret = (string) Settings::get('ildeposito_utils_fb_app_secret', '');
    if ($app_secret !== '') {
      $parameters['appsecret_proof'] = hash_hmac('sha256', $access_token, $app_secret);
    }

    return $parameters;
  }

  /**
   * Restituisce una versione Graph API strettamente valida.
   */
  private function getApiVersion(): string {
    $api_version = (string) Settings::get('ildeposito_utils_fb_graph_api_version', self::DEFAULT_API_VERSION);
    if (!preg_match('/^v\d+\.\d+$/', $api_version)) {
      throw new \LogicException('FB_GRAPH_API_VERSION deve avere il formato vNN.N.');
    }

    return $api_version;
  }

  private function getConfiguredPageId(): string {
    return trim((string) Settings::get('ildeposito_utils_fb_page_id', ''));
  }

  private function getAccessToken(): string {
    return trim((string) Settings::get('ildeposito_utils_fb_system_user_token', ''));
  }

}
