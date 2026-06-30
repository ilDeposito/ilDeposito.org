<?php

declare(strict_types=1);

namespace Drupal\ildeposito_contatti\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\ildeposito_contatti\Entity\IldepositoContatto;
use Symfony\Component\HttpFoundation\RequestStack;

final class IldepositoContattiHooks {

  public function __construct(
    private readonly RequestStack $requestStack,
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

}
