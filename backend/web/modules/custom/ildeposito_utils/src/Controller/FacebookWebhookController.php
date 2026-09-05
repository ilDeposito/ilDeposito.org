<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Site\Settings;
use Drupal\ildeposito_utils\Service\FacebookPageClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Riceve le notifiche feed della Pagina dall'app Meta.
 */
final class FacebookWebhookController extends ControllerBase {

  private const QUEUE_NAME = 'ildeposito_utils_facebook_telegram';

  public function __construct(
    private readonly QueueFactory $queueFactory,
    private readonly FacebookPageClient $facebookPageClient,
  ) {}

  public function receive(Request $request): Response {
    if ($request->isMethod('GET')) {
      return $this->verify($request);
    }

    $raw = (string) $request->getContent();
    if (!$this->hasValidSignature($raw, (string) $request->headers->get('X-Hub-Signature-256', ''))) {
      return new Response('Invalid signature.', Response::HTTP_FORBIDDEN);
    }

    try {
      $payload = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return new Response('Invalid JSON.', Response::HTTP_BAD_REQUEST);
    }

    if (!is_array($payload) || ($payload['object'] ?? NULL) !== 'page') {
      return new Response('', Response::HTTP_NO_CONTENT);
    }

    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    foreach ($payload['entry'] ?? [] as $entry) {
      if (!is_array($entry) || (string) ($entry['id'] ?? '') !== $this->facebookPageClient->getPageId()) {
        continue;
      }
      foreach ($entry['changes'] ?? [] as $change) {
        $value = is_array($change) ? ($change['value'] ?? []) : [];
        $postId = is_array($value) ? ($value['post_id'] ?? NULL) : NULL;
        if (($change['field'] ?? NULL) === 'feed'
          && ($value['verb'] ?? NULL) === 'add'
          && is_string($postId)
          && $postId !== '') {
          $queue->createItem(['facebook_post_id' => $postId]);
        }
      }
    }

    return new Response('', Response::HTTP_OK);
  }

  private function verify(Request $request): Response {
    $verifyToken = (string) Settings::get('ildeposito_utils_fb_webhook_verify_token', '');
    if ($verifyToken === '' || !hash_equals($verifyToken, (string) $request->query->get('hub_verify_token', ''))) {
      return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
    }
    if ($request->query->get('hub_mode') !== 'subscribe') {
      return new Response('Bad request.', Response::HTTP_BAD_REQUEST);
    }
    return new Response((string) $request->query->get('hub_challenge', ''), Response::HTTP_OK, ['Content-Type' => 'text/plain']);
  }

  private function hasValidSignature(string $raw, string $signature): bool {
    $secret = (string) Settings::get('ildeposito_utils_fb_app_secret', '');
    if ($secret === '' || !str_starts_with($signature, 'sha256=')) {
      return FALSE;
    }
    return hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $signature);
  }

}
