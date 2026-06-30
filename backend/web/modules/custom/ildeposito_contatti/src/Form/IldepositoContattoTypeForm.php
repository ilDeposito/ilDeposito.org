<?php

declare(strict_types=1);

namespace Drupal\ildeposito_contatti\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

class IldepositoContattoTypeForm extends EntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\ildeposito_contatti\Entity\IldepositoContattoType $type */
    $type = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Etichetta'),
      '#default_value' => $type->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $type->id(),
      '#machine_name' => [
        'exists' => ['\Drupal\ildeposito_contatti\Entity\IldepositoContattoType', 'load'],
        'source' => ['label'],
      ],
      '#disabled' => !$type->isNew(),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Descrizione'),
      '#default_value' => $type->getDescription(),
      '#rows' => 3,
    ];

    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $type = $this->entity;
    $result = parent::save($form, $form_state);

    $this->messenger()->addStatus(match ($result) {
      SAVED_NEW => $this->t('Tipo contatto %label creato.', ['%label' => $type->label()]),
      SAVED_UPDATED => $this->t('Tipo contatto %label aggiornato.', ['%label' => $type->label()]),
      default => $this->t('Tipo contatto %label salvato.', ['%label' => $type->label()]),
    });

    $form_state->setRedirectUrl($type->toUrl('collection'));
    return $result;
  }

}
