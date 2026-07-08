<?php

declare(strict_types=1);

namespace Drupal\ildeposito_contatti\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Queue\QueueFactory;
use Drupal\ildeposito_contatti\Entity\IldepositoContatto;
use Symfony\Component\HttpFoundation\RequestStack;

final class IldepositoContattiHooks {

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly QueueFactory $queueFactory,
  ) {}

  #[Hook('entity_presave')]
  public function entityPresave(EntityInterface $entity): void {
    if (!$entity instanceof IldepositoContatto || !$entity->isNew()) {
      return;
    }

    // Status sempre 'nuova' per le submission in ingresso: il client non può
    // impostarlo, la moderazione avviene solo dall'interfaccia admin.
    $entity->set('status', 'nuova');

    // IP catturato server-side; il client non può sovrascriverlo.
    $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? '';
    $entity->set('ip_address', $ip);
  }

  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    if (!$entity instanceof IldepositoContatto || $entity->bundle() !== 'modulo_contatti') {
      return;
    }

    // Accodata invece di inviata subito: il form contatti Astro è SSR e resta
    // in attesa della risposta JSON:API, che non deve dipendere dal
    // round-trip SMTP. Vedi NotificaContattoWorker per l'invio effettivo e
    // ContattiQueueTerminateSubscriber per il drenaggio a fine request.
    $this->queueFactory->get('ildeposito_contatti_notifica')->createItem([
      'contatto_id' => $entity->id(),
    ]);
  }

  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    if ($key !== 'notifica_contatto') {
      return;
    }

    // Difesa in profondità: pur affidandoci a symfony_mailer (che già
    // sanitizza gli header), non ci fidiamo implicitamente del mailer
    // configurato per dati liberi finiti in Subject/Reply-To.
    $nome = $this->sanitizeHeaderValue((string) $params['nome']);
    $email = $this->sanitizeHeaderValue((string) $params['email']);

    $message['subject'] = \sprintf('[Contatti] Messaggio ricevuto da %s (%s)', $nome, $email);
    $message['headers']['Reply-To'] = $nome . ' <' . $email . '>';

    $lines = [
      'Nome: ' . $nome,
      'Email: ' . $email,
    ];

    if (!empty($params['titolo'])) {
      $lines[] = 'Titolo: ' . $params['titolo'];
    }

    if (!empty($params['link'])) {
      $lines[] = 'Url: ' . $params['link'];
    }

    $lines[] = '';
    $lines[] = 'Messaggio:';
    $lines[] = $params['messaggio'];

    $message['body'][] = implode("\n", $lines);
  }

  private function sanitizeHeaderValue(string $value): string {
    return trim(str_replace(["\r", "\n"], '', $value));
  }

}
