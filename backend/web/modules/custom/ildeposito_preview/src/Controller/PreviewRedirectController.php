<?php

declare(strict_types=1);

namespace Drupal\ildeposito_preview\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Site\Settings;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Genera un link di anteprima firmato verso il frontend Astro (rotta SSR).
 */
final class PreviewRedirectController extends ControllerBase {

  /**
   * Validità del token di anteprima, in secondi.
   */
  private const TOKEN_TTL = 600;

  public function __construct(
    private readonly TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('datetime.time'));
  }

  /**
   * Reindirizza al frontend con un token HMAC firmato e a scadenza breve.
   *
   * Nota: non chiamarlo `redirect()` — confligge con il metodo helper
   * `ControllerBase::redirect()` (firma incompatibile), causando un fatal
   * error PHP all'abilitazione del modulo.
   */
  public function previewRedirect(NodeInterface $node): Response {
    $secret = (string) Settings::get('ildeposito_preview_secret', '');
    $frontendUrl = (string) Settings::get('ildeposito_preview_frontend_url', '');

    if ($secret === '' || $frontendUrl === '') {
      throw new \RuntimeException('Anteprima frontend non configurata: impostare ildeposito_preview_secret e ildeposito_preview_frontend_url in settings.php.');
    }

    $uuid = $node->uuid();
    $expires = (string) ($this->time->getRequestTime() + self::TOKEN_TTL);
    $token = hash_hmac('sha256', "{$uuid}:{$expires}", $secret);

    $url = rtrim($frontendUrl, '/') . '/preview/' . rawurlencode($uuid) . '?' . http_build_query([
      'type' => $node->bundle(),
      'expires' => $expires,
      'token' => $token,
    ]);

    // TrustedRedirectResponse è necessaria perché il target è un dominio
    // esterno (frontend Astro), non una rotta interna a Drupal.
    return new TrustedRedirectResponse($url);
  }

}
