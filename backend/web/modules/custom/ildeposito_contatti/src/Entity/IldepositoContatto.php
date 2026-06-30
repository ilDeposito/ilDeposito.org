<?php

declare(strict_types=1);

namespace Drupal\ildeposito_contatti\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[ContentEntityType(
  id: 'ildeposito_contatto',
  label: new TranslatableMarkup('Contatto'),
  label_collection: new TranslatableMarkup('Contatti'),
  label_singular: new TranslatableMarkup('contatto'),
  label_plural: new TranslatableMarkup('contatti'),
  bundle_label: new TranslatableMarkup('Tipo contatto'),
  handlers: [
    'view_builder' => 'Drupal\Core\Entity\EntityViewBuilder',
    'list_builder' => 'Drupal\ildeposito_contatti\IldepositoContattoListBuilder',
    'form' => [
      'default' => 'Drupal\Core\Entity\ContentEntityForm',
      'add' => 'Drupal\Core\Entity\ContentEntityForm',
      'edit' => 'Drupal\Core\Entity\ContentEntityForm',
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'access' => 'Drupal\ildeposito_contatti\IldepositoContattoAccessControlHandler',
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
    ],
    // Rende l'entity disponibile come base table in Views UI.
    // Il modulo views deve essere abilitato per usare questo handler.
    'views_data' => 'Drupal\views\EntityViewsData',
  ],
  base_table: 'ildeposito_contatto',
  entity_keys: [
    'id' => 'id',
    'bundle' => 'bundle',
    'uuid' => 'uuid',
    'label' => 'id',
  ],
  bundle_entity_type: 'ildeposito_contatto_type',
  // Attiva "Gestisci campi / Gestisci visualizzazione" in Field UI per ogni bundle.
  field_ui_base_route: 'entity.ildeposito_contatto_type.edit_form',
  admin_permission: 'administer ildeposito contatti',
  links: [
    'canonical' => '/admin/content/ildeposito-contatto/{ildeposito_contatto}',
    'edit-form' => '/admin/content/ildeposito-contatto/{ildeposito_contatto}/modifica',
    'delete-form' => '/admin/content/ildeposito-contatto/{ildeposito_contatto}/elimina',
    'collection' => '/admin/content/ildeposito-contatto',
    'add-page' => '/admin/content/ildeposito-contatto/add',
    'add-form' => '/admin/content/ildeposito-contatto/add/{ildeposito_contatto_type}',
  ],
)]
class IldepositoContatto extends ContentEntityBase {

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    // Il parent aggiunge automaticamente 'id' e 'uuid' dall'entity_keys.
    $fields = parent::baseFieldDefinitions($entity_type);

    // Campo bundle standard (entity_reference al tipo di contatto),
    // identico all'approccio usato da Node ('type') e TaxonomyTerm ('vid').
    $fields['bundle'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Tipo contatto'))
      ->setSetting('target_type', 'ildeposito_contatto_type')
      ->setRequired(TRUE)
      ->setReadOnly(TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Stato'))
      ->setDefaultValue('nuova')
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        'nuova' => 'Nuova',
        'letta' => 'Letta',
        'gestita' => 'Gestita',
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['ip_address'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Indirizzo IP'))
      // 45 caratteri coprono sia IPv4 che IPv6 con notazione mapped (::ffff:x.x.x.x).
      ->setSetting('max_length', 45)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Data invio'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 30,
      ])
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  public function getStatus(): string {
    return $this->get('status')->value ?? 'nuova';
  }

  public function setStatus(string $status): static {
    $this->set('status', $status);
    return $this;
  }

  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  public function getIpAddress(): string {
    return (string) ($this->get('ip_address')->value ?? '');
  }

}
