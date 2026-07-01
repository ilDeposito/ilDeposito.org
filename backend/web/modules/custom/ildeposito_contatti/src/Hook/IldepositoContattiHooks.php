<?php

declare(strict_types=1);

namespace Drupal\ildeposito_contatti\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ildeposito_contatti\Entity\IldepositoContatto;
use Symfony\Component\HttpFoundation\RequestStack;

final class IldepositoContattiHooks {

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly MailManagerInterface $mailManager,
    private readonly StateInterface $state,
    private readonly LanguageManagerInterface $languageManager,
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

    $destinatari = (string) $this->state->get('contatti_destinatari', '');
    if ($destinatari === '') {
      return;
    }

    // Valida ogni indirizzo e ricomponi la stringa ripulita.
    $indirizzi = array_filter(
      array_map('trim', explode(',', $destinatari)),
      static fn(string $addr): bool => filter_var($addr, FILTER_VALIDATE_EMAIL) !== FALSE,
    );
    if (empty($indirizzi)) {
      return;
    }

    $nome = (string) ($entity->get('field_nome')->value ?? '');
    $email = (string) ($entity->get('field_email')->value ?? '');
    $messaggio = (string) ($entity->get('field_messaggio')->value ?? '');

    $this->mailManager->mail(
      module: 'ildeposito_contatti',
      key: 'notifica_contatto',
      to: implode(', ', $indirizzi),
      langcode: $this->languageManager->getDefaultLanguage()->getId(),
      params: [
        'nome' => $nome,
        'email' => $email,
        'messaggio' => $messaggio,
      ],
    );
  }

  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    if ($key !== 'notifica_contatto') {
      return;
    }

    $message['subject'] = sprintf(
      '[Contatti] Messaggio ricevuto da %s (%s)',
      $params['nome'],
      $params['email'],
    );

    $message['body'][] = implode("\n", [
      'Nome: ' . $params['nome'],
      'Email: ' . $params['email'],
      'Messaggio:',
      $params['messaggio'],
    ]);
  }

}
