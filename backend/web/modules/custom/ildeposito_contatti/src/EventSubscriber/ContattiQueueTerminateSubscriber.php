<?php

declare(strict_types=1);

namespace Drupal\ildeposito_contatti\EventSubscriber;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Drena la coda notifiche contatti subito dopo l'invio della response.
 *
 * kernel.terminate gira dopo che Response::send() ha già restituito i byte
 * al client (fastcgi_finish_request), quindi processare qui non introduce
 * latenza percepita per il form contatti SSR, pur restando disaccoppiato
 * dalla request. Il cron (impostazione #[cron] su NotificaContattoWorker)
 * resta rete di sicurezza per le request che non arrivano a terminate.
 */
final class ContattiQueueTerminateSubscriber implements EventSubscriberInterface {

  private const QUEUE_NAME = 'ildeposito_contatti_notifica';

  public function __construct(
    private readonly QueueFactory $queueFactory,
    private readonly QueueWorkerManagerInterface $queueWorkerManager,
    private readonly LoggerInterface $logger,
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
      catch (RequeueException) {
        $queue->releaseItem($item);
      }
      catch (\Throwable $e) {
        // Non ritentiamo qui all'infinito: l'item resta in coda (rilasciato,
        // non cancellato) e verrà ripreso dal cron, che ha il proprio limite
        // di tempo/tentativi.
        $queue->releaseItem($item);
        $this->logger->error('Invio notifica contatto fallito: @message', ['@message' => $e->getMessage()]);
        return;
      }
    }
  }

}
