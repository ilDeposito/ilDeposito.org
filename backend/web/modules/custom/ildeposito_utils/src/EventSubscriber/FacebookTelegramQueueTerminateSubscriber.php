<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Esegue subito la replica dopo avere risposto al webhook Meta.
 *
 * Drupal cron resta il recupero per errori di processo o consegna webhook.
 */
final class FacebookTelegramQueueTerminateSubscriber implements EventSubscriberInterface {

  private const QUEUE_NAME = 'ildeposito_utils_facebook_telegram';

  public function __construct(
    private readonly QueueFactory $queueFactory,
    private readonly QueueWorkerManagerInterface $queueWorkerManager,
    private readonly LoggerChannelInterface $logger,
  ) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => 'onTerminate'];
  }

  public function onTerminate(TerminateEvent $event): void {
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    if ($queue->numberOfItems() === 0) {
      return;
    }

    $worker = $this->queueWorkerManager->createInstance(self::QUEUE_NAME);
    while ($item = $queue->claimItem()) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
      }
      catch (\Throwable $exception) {
        $queue->releaseItem($item);
        $this->logger->error('Replica Facebook -> Telegram fallita: @message', ['@message' => $exception->getMessage()]);
        return;
      }
    }
  }

}
