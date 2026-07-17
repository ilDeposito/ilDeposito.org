<?php

declare(strict_types=1);

namespace Drupal\ildeposito_auth\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;

final class IldepositoAuthHooks {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_openid_connect_redirect_logout_alter().
   *
   * Authelia non pubblica un end_session_endpoint OIDC (RP-Initiated Logout
   * non implementato: assente dalla sua discovery). Senza questo hook,
   * openid_connect si limita a chiudere la sessione Drupal e a loggare un
   * warning ("does not support log out"), ma la sessione su Authelia resta
   * viva: al primo redirect anonimo (AnonymousLoginRedirect + autostart_login)
   * l'utente si ritroverebbe rilogato all'istante, senza aver mai visto un
   * vero logout. Qui si forza il redirect sulla pagina di logout nativa di
   * Authelia (non uno standard OIDC, la sua UI) che invalida quella sessione.
   */
  #[Hook('openid_connect_redirect_logout_alter')]
  public function openidConnectRedirectLogoutAlter(array &$rsp, array $context): void {
    if (($context['client'] ?? NULL) !== 'authelia') {
      return;
    }

    $issuer = $this->configFactory->get('openid_connect.client.authelia')->get('settings.issuer_url');
    if (empty($issuer)) {
      return;
    }

    $destination = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl();
    $logoutUrl = Url::fromUri(rtrim((string) $issuer, '/') . '/logout', [
      'query' => ['rd' => $destination],
    ])->toString(TRUE);

    $rsp['response'] = new TrustedRedirectResponse($logoutUrl->getGeneratedUrl());
  }

}
