<?php

declare(strict_types=1);

namespace Drupal\ildeposito_contatti\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDescriptionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[ConfigEntityType(
  id: 'ildeposito_contatto_type',
  label: new TranslatableMarkup('Tipo contatto'),
  label_collection: new TranslatableMarkup('Tipi di contatto'),
  label_singular: new TranslatableMarkup('tipo contatto'),
  label_plural: new TranslatableMarkup('tipi di contatto'),
  handlers: [
    'list_builder' => 'Drupal\ildeposito_contatti\IldepositoContattoTypeListBuilder',
    'form' => [
      'add' => 'Drupal\ildeposito_contatti\Form\IldepositoContattoTypeForm',
      'edit' => 'Drupal\ildeposito_contatti\Form\IldepositoContattoTypeForm',
      'delete' => 'Drupal\Core\Entity\EntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
    ],
  ],
  admin_permission: 'administer ildeposito contatti',
  // Dichiara che questa config entity è il bundle type per ildeposito_contatto.
  bundle_of: 'ildeposito_contatto',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
  ],
  links: [
    'add-form' => '/admin/structure/ildeposito-contatto-types/add',
    'edit-form' => '/admin/structure/ildeposito-contatto-types/{ildeposito_contatto_type}/modifica',
    'delete-form' => '/admin/structure/ildeposito-contatto-types/{ildeposito_contatto_type}/elimina',
    'collection' => '/admin/structure/ildeposito-contatto-types',
  ],
  config_prefix: 'type',
  config_export: ['id', 'label', 'description'],
)]
class IldepositoContattoType extends ConfigEntityBundleBase implements EntityDescriptionInterface {

  protected string $id;
  protected string $label;
  protected string $description = '';

  public function getDescription(): string {
    return $this->description;
  }

  public function setDescription($description): static {
    $this->description = (string) $description;
    return $this;
  }

}
