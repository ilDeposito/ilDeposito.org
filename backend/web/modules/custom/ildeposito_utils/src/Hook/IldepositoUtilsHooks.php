<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;

final class IldepositoUtilsHooks {

  #[Hook('entity_presave')]
  public function entityPresave(EntityInterface $entity): void {
    if ($entity instanceof NodeInterface && $entity->bundle() === 'autore') {
      $this->setAutoreTitle($entity);
    }
  }

  #[Hook('mail_alter')]
  public function mailAlter(array &$message): void {
    $from = getenv('MAIL_FROM');
    if ($from === false || $from === '') {
      return;
    }
    $message['from'] = $from;
    $message['headers']['From'] = $from;
    $message['headers']['Sender'] = $from;
    $message['headers']['Return-Path'] = $from;
  }

  private function setAutoreTitle(NodeInterface $node): void {
    $cognome = trim((string) $node->get('field_cognome')->value);
    $nome = trim((string) $node->get('field_nome')->value);

    $node->setTitle($nome !== '' ? "$nome $cognome" : $cognome);
  }

}
