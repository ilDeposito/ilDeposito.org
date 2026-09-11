<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ildeposito_utils\Service\InstagramTelegramNotifier;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Inoltra a Telegram le interazioni Instagram ricevute da Meta. */
#[QueueWorker(
  id: 'ildeposito_utils_instagram_telegram',
  title: new TranslatableMarkup('Notifiche Instagram Telegram'),
  cron: ['time' => 30],
)]
final class InstagramTelegramWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly InstagramTelegramNotifier $notifier,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(InstagramTelegramNotifier::class),
    );
  }

  public function processItem($data): void {
    if (is_array($data)) {
      $this->notifier->notify($data);
    }
  }

}
