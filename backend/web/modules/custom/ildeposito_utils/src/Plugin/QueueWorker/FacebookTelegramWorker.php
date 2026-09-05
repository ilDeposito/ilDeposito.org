<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ildeposito_utils\Service\FacebookTelegramPublisher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pubblica sul canale Telegram i post segnalati dal webhook Facebook.
 */
#[QueueWorker(
  id: 'ildeposito_utils_facebook_telegram',
  title: new TranslatableMarkup('Replica Facebook Telegram'),
  cron: ['time' => 30],
)]
final class FacebookTelegramWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly FacebookTelegramPublisher $publisher,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get(FacebookTelegramPublisher::class));
  }

  public function processItem($data): void {
    $postId = is_array($data) ? ($data['facebook_post_id'] ?? NULL) : NULL;
    if (!is_string($postId) || $postId === '') {
      return;
    }
    $this->publisher->publish($postId);
  }

}
