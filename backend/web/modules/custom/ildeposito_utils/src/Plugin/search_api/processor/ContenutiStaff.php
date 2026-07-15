<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Plugin\search_api\processor;

use Drupal\node\NodeInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Calcola field_contenuti_staff: completezza dei campi editoriali.
 *
 * Per ogni nodo canto/autore/evento, aggiunge una coppia "con X"/"senza X"
 * per ciascun campo rilevante ai fini editoriali (vedi CAMPI_PER_BUNDLE),
 * prefissata col bundle per evitare ambiguità quando canti, autori ed
 * eventi condividono lo stesso indice (es. "tags"/"tematiche" compaiono
 * sia su canto sia su evento). Usato per costruire facet staff (Facets +
 * Better Exposed Filters) di controllo qualità editoriale, non esposto
 * al frontend Astro.
 *
 * @SearchApiProcessor(
 *   id = "ildeposito_contenuti_staff",
 *   label = @Translation("Contenuti staff"),
 *   description = @Translation("Aggiunge field_contenuti_staff: indicatori di completezza dei campi editoriali (con/senza accordi, audio, immagine, ecc.) per canti, autori ed eventi."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
final class ContenutiStaff extends ProcessorPluginBase {

  /**
   * Nome della proprietà calcolata esposta all'indice.
   */
  private const FIELD_NAME = 'field_contenuti_staff';

  /**
   * Campi da verificare per bundle, con relativa etichetta staff.
   *
   * Formato: bundle => [nome_campo => etichetta leggibile].
   */
  private const CAMPI_PER_BUNDLE = [
    'canto' => [
      'field_canto_accordi' => 'accordi',
      'field_anno' => 'anno',
      'field_autori_testo' => 'autori',
      'field_informazioni' => 'informazioni',
      'field_audio' => 'audio',
      'field_tematiche' => 'tematiche',
      'field_tags' => 'tag',
      'field_fonte' => 'fonte',
      'field_canti_correlati' => 'canti correlati',
    ],
    'autore' => [
      'field_immagine' => 'immagine',
      'field_links' => 'link',
      'field_informazioni' => 'informazioni',
      'field_autori_correlati' => 'autori correlati',
      'field_anno_di_nascita' => 'anno di nascita',
      'field_anno_di_morte' => 'anno di morte',
    ],
    'evento' => [
      'field_tags' => 'tag',
      'field_tematiche' => 'tematiche',
    ],
  ];

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL): array {
    // Proprietà valida solo per il datasource "entity:node" (o quando Search
    // API interroga le proprietà generiche, con $datasource NULL).
    if ($datasource !== NULL && $datasource->getEntityTypeId() !== 'node') {
      return [];
    }

    $definition = [
      'label' => $this->t('Contenuti staff'),
      'description' => $this->t('Indicatori "con/senza" di completezza dei campi editoriali, per i facet staff.'),
      'type' => 'string',
      'processor_id' => $this->getPluginId(),
    ];

    return [self::FIELD_NAME => new ProcessorProperty($definition)];
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item): void {
    $entity = $item->getOriginalObject()->getValue();
    if (!$entity instanceof NodeInterface) {
      return;
    }

    $campi = self::CAMPI_PER_BUNDLE[$entity->bundle()] ?? NULL;
    if ($campi === NULL) {
      return;
    }

    // La proprietà è generica (registrata quando $datasource === NULL in
    // getPropertyDefinitions()), quindi sull'indice il campo ha
    // datasource_id = NULL: va confrontato con NULL, non con l'id del
    // datasource dell'item, perché filterForPropertyPath() usa un confronto
    // stretto.
    $fields = $this->getFieldsHelper()->filterForPropertyPath(
      $item->getFields(),
      NULL,
      self::FIELD_NAME,
    );
    if (!$fields) {
      return;
    }

    $bundleLabel = ucfirst($entity->bundle());
    foreach ($campi as $nomeCampo => $etichetta) {
      $pieno = $entity->hasField($nomeCampo) && !$entity->get($nomeCampo)->isEmpty();
      $stato = $pieno ? 'con' : 'senza';
      $valore = "{$bundleLabel}: {$stato} {$etichetta}";

      foreach ($fields as $field) {
        $field->addValue($valore);
      }
    }
  }

}
