<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Comandi Drush per il modulo ildeposito_utils.
 */
final class IldepositoUtilsCommands extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {
    parent::__construct();
  }

  /**
   * Crea il file e il media immagine di default.
   */
  #[CLI\Command(name: 'ildeposito:create-default-media', aliases: ['icdm'])]
  #[CLI\Usage(name: 'ildeposito:create-default-media', description: 'Crea il file e il media immagine di default per ilDeposito.')]
  public function createDefaultMedia(): void {
    $module_path = $this->moduleExtensionList->getPath('ildeposito_utils');
    $source_path = DRUPAL_ROOT . '/' . $module_path . '/assets/immagine.jpg';

    if (!is_file($source_path)) {
      $this->logger()->error(dt('File sorgente non trovato in @path.', ['@path' => $source_path]));
      return;
    }

    $destination_directory = 'public://ildeposito-utils';
    $this->fileSystem->prepareDirectory(
      $destination_directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );

    $destination_uri = $destination_directory . '/immagine.jpg';

    $existing_file_ids = \Drupal::entityQuery('file')
      ->accessCheck(FALSE)
      ->condition('uri', $destination_uri)
      ->range(0, 1)
      ->execute();

    if ($existing_file_ids !== []) {
      $file = $this->entityTypeManager->getStorage('file')->load(reset($existing_file_ids));
      $this->logger()->notice(dt('File già esistente (fid: @fid).', ['@fid' => $file->id()]));
    }
    else {
      if (!file_exists($destination_uri)) {
        $this->fileSystem->copy($source_path, $destination_uri, FileExists::Replace);
      }

      $file = File::create([
        'uid' => 1,
        'status' => 1,
        'uri' => $destination_uri,
      ]);
      $file->setPermanent();
      $file->save();
      $this->logger()->success(dt('File creato (fid: @fid).', ['@fid' => $file->id()]));
    }

    if (!$file instanceof File) {
      $this->logger()->error(dt('File Drupal non disponibile per @uri.', ['@uri' => $destination_uri]));
      return;
    }

    $file->setPermanent();
    $file->save();

    $media_type = $this->entityTypeManager->getStorage('media_type')->load('image');
    if (!$media_type || !$media_type->getSource()) {
      $this->logger()->error(dt('Media type "image" non disponibile o senza source.'));
      return;
    }

    $existing_media_ids = \Drupal::entityQuery('media')
      ->accessCheck(FALSE)
      ->condition('bundle', 'image')
      ->condition('field_media_image.target_id', $file->id())
      ->range(0, 1)
      ->execute();

    if ($existing_media_ids !== []) {
      $this->logger()->notice(dt('Media già esistente.'));
      return;
    }

    $media = Media::create([
      'bundle' => 'image',
      'name' => 'immagine',
      'status' => 1,
      'uid' => 1,
      'langcode' => 'it',
      'field_header' => '1',
      'field_media_image' => [[
        'target_id' => $file->id(),
        'alt' => 'immagine',
      ]],
    ]);
    $media->save();
    $this->logger()->success(dt('Media creato (mid: @mid).', ['@mid' => $media->id()]));


    // Creazione nodi "page" iniziali con alias specifici
    $pages = [
        'Archivio' => '/archivio',
        'Presentazione dell\'archivio' => '/archivio/presentazione',
        'I contenuti' => '/archivio/contenuti',
        'La catalogazione' => '/archivio/catalogazione',
        'Informazioni' => '/informazioni',
        'Note legali' => '/informazioni/note-legali',
        'Collabora con noi' => '/informazioni/collabora',
        'Lo staff de ilDeposito' => '/informazioni/staff',
        'Privacy & Cookie Policy' => '/informazioni/privacy-cookie-policy',
        'Canti' => '/canti',
        'Eventi' => '/eventi',
        'Autori' => '/autori',
        'Traduzioni' => '/traduzioni',
        'Percorsi' => '/percorsi',
        'Lingue' => '/lingue',
        'Localizzazioni' => '/localizzazioni',
        'Periodi' => '/periodi',
        'Tags' => '/tags',
        'Tematiche' => '/tematiche',
    ];

    // cicla l'array $pages come $title e $alias e crea, per ogni elemento, crea un nodo di tipo "pagina" in italiano, con titolo $title e alias $alias
    foreach ($pages as $title => $alias) {
        $node = Node::create([
            'type' => 'pagina',
            'title' => $title,
            'langcode' => 'it',
            'status' => 1,
            'uid' => 1,
            'field_descrizione_header' => 'Descrizione pagina ' . $title,        
        ]);
        $node->save();

         // Crea l'alias per il nodo appena creato (Drupal 10/11)
        \Drupal\path_alias\Entity\PathAlias::create([
            'path' => '/node/' . $node->id(),
            'alias' => $alias,
            'langcode' => 'it',
        ])->save();
    }
  }

}
