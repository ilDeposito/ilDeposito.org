<?php

declare(strict_types=1);

namespace Drupal\ildeposito_contatti;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

class IldepositoContattoTypeListBuilder extends ConfigEntityListBuilder {

  public function buildHeader(): array {
    return [
      'label' => $this->t('Tipo'),
      'id' => $this->t('Machine name'),
      'description' => $this->t('Descrizione'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\ildeposito_contatti\Entity\IldepositoContattoType $entity */
    return [
      'label' => $entity->label(),
      'id' => $entity->id(),
      'description' => $entity->getDescription(),
    ] + parent::buildRow($entity);
  }

}
