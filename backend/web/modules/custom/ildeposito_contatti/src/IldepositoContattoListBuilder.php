<?php

declare(strict_types=1);

namespace Drupal\ildeposito_contatti;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class IldepositoContattoListBuilder extends EntityListBuilder {

  private const STATUS_LABELS = [
    'nuova' => 'Nuova',
    'letta' => 'Letta',
    'gestita' => 'Gestita',
  ];

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    private readonly DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($entity_type, $storage);
  }

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter'),
    );
  }

  public function buildHeader(): array {
    return [
      'id' => $this->t('ID'),
      'bundle' => $this->t('Tipo'),
      'status' => $this->t('Stato'),
      'ip_address' => $this->t('IP'),
      'created' => $this->t('Data invio'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\ildeposito_contatti\Entity\IldepositoContatto $entity */
    $bundle_entity = $entity->get('bundle')->entity;
    $status = $entity->getStatus();

    return [
      'id' => $entity->toLink((string) $entity->id()),
      'bundle' => $bundle_entity ? $bundle_entity->label() : $entity->bundle(),
      'status' => self::STATUS_LABELS[$status] ?? $status,
      'ip_address' => $entity->getIpAddress(),
      'created' => $this->dateFormatter->format($entity->getCreatedTime(), 'short'),
    ] + parent::buildRow($entity);
  }

}
