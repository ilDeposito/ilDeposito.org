<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Drush\Commands;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\ildeposito_utils\Service\FacebookPageClient;
use Drupal\ildeposito_utils\Service\FacebookScheduledCommentManager;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Pubblica direttamente sulla Pagina Facebook gli eventi del giorno.
 *
 * Per ogni evento con anniversario oggi crea un post fotografico programmato,
 * usa field_descrizione_social come testo e aggiunge il link pubblico del
 * nodo nel primo commento dopo la pubblicazione.
 */
#[AsCommand(
  name: self::NAME,
  description: 'Programma foto, descrizione e commento con link per gli eventi con anniversario oggi.',
  aliases: ['iufbeventigiorno'],
)]
final class FbEventiGiornoCommand extends Command {

  use AutowireTrait;

  public const NAME = 'ildeposito:fb-eventigiorno';

  // Stesse fasce del precedente ildeposito:fb-post, che delegava la
  // programmazione a Make.com.
  private const HOURS_SCHEDULE = [
    1 => ['7'],
    2 => ['7', '13'],
    3 => ['7', '13', '17'],
    4 => ['7', '12', '16', '18'],
  ];
  private const HOURS_SCHEDULE_DEFAULT = ['7', '9', '11', '13', '15', '17', '19'];

  // Meta richiede che scheduled_publish_time sia almeno dieci minuti nel
  // futuro. Il margine evita chiamate destinate a fallire se il comando viene
  // lanciato a ridosso della prima fascia.
  private const MINIMUM_SCHEDULE_DELAY = 600;

  // In CLI Drush non esiste un request context affidabile: il link deve
  // puntare al frontend pubblico, non all'host backend Drupal.
  private const PUBLIC_BASE_URL = 'https://www.ildeposito.org';

  // Il frontend Astro genera questa copia statica (in scala di grigi) per
  // ciascuna immagine di evento. Facebook deve ricevere questa URL pubblica,
  // non il file originale servito da Drupal.
  private const FRONTEND_EVENT_IMAGES_BASE_URL = self::PUBLIC_BASE_URL . '/uploads/eventi';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly FacebookPageClient $facebookPageClient,
    private readonly FacebookScheduledCommentManager $facebookScheduledCommentManager,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this->addOption(
      'dry-run',
      NULL,
      InputOption::VALUE_NONE,
      'Mostra gli eventi selezionati senza inviare chiamate a Facebook.',
    );
    $this->addOption(
      'comments-only',
      NULL,
      InputOption::VALUE_NONE,
      'Invia soltanto i commenti dei post programmati che Facebook ha già pubblicato.',
    );
    $this->addOption(
      'test-immediate',
      NULL,
      InputOption::VALUE_NONE,
      'TEST TEMPORANEO: pubblica subito soltanto il primo evento del giorno e il relativo commento.',
    );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $dry_run = (bool) $input->getOption('dry-run');
    $comments_only = (bool) $input->getOption('comments-only');
    $test_immediate = (bool) $input->getOption('test-immediate');
    if (!$dry_run && !$this->facebookPageClient->isConfigured()) {
      $output->writeln('<comment>Facebook Graph API non configurata (servono FB_PAGE_ID e FB_SYSTEM_USER_TOKEN): comando disattivato in questo ambiente.</comment>');
      return Command::SUCCESS;
    }

    if ($comments_only) {
      if ($dry_run) {
        $output->writeln('<info>[dry-run] Nessun commento Facebook viene inviato.</info>');
        return Command::SUCCESS;
      }

      $published = $this->facebookScheduledCommentManager->processDueComments();
      $output->writeln(sprintf('<info>%d commenti Facebook pubblicati.</info>', $published));
      return Command::SUCCESS;
    }

    if ($test_immediate) {
      return $this->executeImmediateTest($dry_run, $output);
    }

    $events = $this->getEventiAnniversarioOggi();
    if ($events === []) {
      $output->writeln('Nessun evento con anniversario oggi.');
      return Command::SUCCESS;
    }

    $hours = self::HOURS_SCHEDULE[count($events)] ?? self::HOURS_SCHEDULE_DEFAULT;
    $now = new DrupalDateTime('now');
    $scheduled = 0;
    foreach (array_values($events) as $key => $event) {
      $hour = $hours[$key] ?? NULL;
      if ($hour === NULL) {
        $this->reportSkip($event, $output, 'nessuna fascia oraria disponibile');
        continue;
      }

      $scheduled_at = new DrupalDateTime('today ' . $hour . ':00:00');
      if ($scheduled_at->getTimestamp() < $now->getTimestamp() + self::MINIMUM_SCHEDULE_DELAY) {
        $this->reportSkip($event, $output, 'fascia oraria già trascorsa o troppo vicina');
        continue;
      }

      $description = trim((string) $event->get('field_descrizione_social')->value);
      if ($description === '') {
        $this->reportSkip($event, $output, 'field_descrizione_social vuoto');
        continue;
      }

      $file = $this->getImageFile($event);
      if (!$file instanceof FileInterface) {
        $this->reportSkip($event, $output, 'field_immagine assente o non valida');
        continue;
      }

      $image_url = $this->getFrontendEventImageUrl($file);
      if ($image_url === NULL) {
        $this->reportSkip($event, $output, 'URL immagine frontend non disponibile');
        continue;
      }

      $link = Url::fromRoute('entity.node.canonical', ['node' => $event->id()], [
        'absolute' => TRUE,
        'base_url' => self::PUBLIC_BASE_URL,
      ])->toString();
      $description = $this->removeUrlsFromDescription($description);
      if ($description === '') {
        $this->reportSkip($event, $output, 'field_descrizione_social contiene soltanto URL');
        continue;
      }
      $message = $this->buildFacebookMessage($description);

      if ($dry_run) {
        $scheduled++;
        $output->writeln(sprintf('<info>[dry-run] Evento %d: foto + testo alle %s + commento %s</info>', $event->id(), $scheduled_at->format('H:i'), $link));
        continue;
      }

      try {
        $photo_id = $this->createUnpublishedPhoto($image_url);
        $post_id = $this->schedulePhotoPost($photo_id, $message, $scheduled_at->getTimestamp());
        $this->facebookScheduledCommentManager->schedule($post_id, $link, $scheduled_at->getTimestamp());
      }
      catch (\Throwable $exception) {
        $this->logger()->error('Pubblicazione Facebook fallita per evento @nid: @message', [
          '@nid' => $event->id(),
          '@message' => $exception->getMessage(),
        ]);
        $output->writeln(sprintf('<error>Evento %d: programmazione Facebook fallita (%s).</error>', $event->id(), $exception->getMessage()));
        continue;
      }

      $scheduled++;
      $output->writeln(sprintf('<info>Evento %d programmato su Facebook alle %s.</info>', $event->id(), $scheduled_at->format('H:i')));
    }

    $label = $dry_run ? 'verificati' : 'programmati';
    $output->writeln(sprintf('<info>%d/%d eventi %s.</info>', $scheduled, count($events), $label));

    return Command::SUCCESS;
  }

  /**
   * Pubblica subito il primo evento del giorno per verificare l'integrazione.
   */
  private function executeImmediateTest(bool $dry_run, OutputInterface $output): int {
    $events = $this->getEventiAnniversarioOggi();
    $event = reset($events);
    if (!$event instanceof NodeInterface) {
      $output->writeln('Nessun evento con anniversario oggi.');
      return Command::SUCCESS;
    }

    $description = trim((string) $event->get('field_descrizione_social')->value);
    $description = $this->removeUrlsFromDescription($description);
    if ($description === '') {
      $this->reportSkip($event, $output, 'field_descrizione_social contiene soltanto URL');
      return Command::FAILURE;
    }

    $file = $this->getImageFile($event);
    if (!$file instanceof FileInterface) {
      $this->reportSkip($event, $output, 'field_immagine assente o non valida');
      return Command::FAILURE;
    }

    $image_url = $this->getFrontendEventImageUrl($file);
    if ($image_url === NULL) {
      $this->reportSkip($event, $output, 'URL immagine frontend non disponibile');
      return Command::FAILURE;
    }

    $link = Url::fromRoute('entity.node.canonical', ['node' => $event->id()], [
      'absolute' => TRUE,
      'base_url' => self::PUBLIC_BASE_URL,
    ])->toString();
    $message = $this->buildFacebookMessage($description);

    if ($dry_run) {
      $output->writeln(sprintf('<info>[dry-run] Test evento %d: pubblicazione immediata + commento %s</info>', $event->id(), $link));
      return Command::SUCCESS;
    }

    try {
      $post_id = $this->publishPhotoImmediately($image_url, $message);
      $this->facebookPageClient->post($post_id . '/comments', ['message' => $link]);
    }
    catch (\Throwable $exception) {
      $this->logger()->error('Test pubblicazione Facebook fallito per evento @nid: @message', [
        '@nid' => $event->id(),
        '@message' => $exception->getMessage(),
      ]);
      $output->writeln(sprintf('<error>Test evento %d: pubblicazione Facebook fallita (%s).</error>', $event->id(), $exception->getMessage()));
      return Command::FAILURE;
    }

    $output->writeln(sprintf('<info>Test evento %d pubblicato subito su Facebook con commento.</info>', $event->id()));
    return Command::SUCCESS;
  }

  /**
   * Carica una foto non pubblicata e restituisce il suo media_fbid.
   */
  private function createUnpublishedPhoto(string $image_url): string {
    $response = $this->facebookPageClient->postToPage('photos', [
      'published' => 'false',
      'url' => $image_url,
    ]);

    try {
      $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException('Facebook ha restituito una risposta non JSON.', 0, $exception);
    }

    if (!is_array($payload)) {
      throw new \RuntimeException('Facebook ha restituito una risposta non valida per il caricamento della foto.');
    }

    $photo_id = $payload['id'] ?? NULL;
    if (!is_string($photo_id) || $photo_id === '') {
      throw new \RuntimeException('Facebook non ha restituito l’ID della foto non pubblicata.');
    }

    return $photo_id;
  }

  /**
   * Pubblica subito una foto con didascalia e restituisce l'ID del post.
   */
  private function publishPhotoImmediately(string $image_url, string $message): string {
    $response = $this->facebookPageClient->postToPage('photos', [
      'caption' => $message,
      'url' => $image_url,
    ]);

    try {
      $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException('Facebook ha restituito una risposta non JSON.', 0, $exception);
    }

    $post_id = is_array($payload) ? ($payload['post_id'] ?? NULL) : NULL;
    if (!is_string($post_id) || $post_id === '') {
      throw new \RuntimeException('Facebook non ha restituito l’ID del post fotografico.');
    }

    return $post_id;
  }

  /**
   * Crea il post della Pagina, allegando la foto e programmando l'orario.
   */
  private function schedulePhotoPost(string $photo_id, string $description, int $scheduled_publish_time): string {
    $response = $this->facebookPageClient->postToPage('feed', [
      'message' => $description,
      'published' => 'false',
      'scheduled_publish_time' => (string) $scheduled_publish_time,
      'attached_media[0]' => json_encode(['media_fbid' => $photo_id], JSON_THROW_ON_ERROR),
    ]);

    try {
      $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException('Facebook ha restituito una risposta non JSON per il post programmato.', 0, $exception);
    }

    $post_id = is_array($payload) ? ($payload['id'] ?? NULL) : NULL;
    if (!is_string($post_id) || $post_id === '') {
      throw new \RuntimeException('Facebook non ha restituito l’ID del post programmato.');
    }

    return $post_id;
  }

  private function getImageFile(NodeInterface $event): ?FileInterface {
    $media = $event->get('field_immagine')->entity;
    if (!$media instanceof MediaInterface || !$media->hasField('field_media_image')) {
      return NULL;
    }

    $file = $media->get('field_media_image')->entity;
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * Ricostruisce l'URL della copia pubblica generata dal frontend Astro.
   */
  private function getFrontendEventImageUrl(FileInterface $file): ?string {
    $source_url = $this->fileUrlGenerator->generateString($file->getFileUri());
    if ($source_url === '') {
      return NULL;
    }

    // Il builder frontend usa l'URL relativo di JSON:API come input della
    // MD5. FileUrlGenerator restituisce lo stesso percorso (/sites/...)
    // anche da Drush, senza dipendere dall'host locale.
    if (!str_starts_with($source_url, '/')) {
      $source_url = '/' . $source_url;
    }
    preg_match('/\\.\\w+$/', $source_url, $matches);
    $extension = $matches[0] ?? '.jpg';

    return sprintf('%s/%s%s', self::FRONTEND_EVENT_IMAGES_BASE_URL, substr(md5($source_url), 0, 12), $extension);
  }

  /**
   * Rimuove dal post principale tutti gli URL, inclusi quelli Bitly.
   *
   * Il commento viene costruito separatamente con l'URL canonico del nodo,
   * perciò non dipende dai collegamenti eventualmente presenti nel campo.
   */
  private function removeUrlsFromDescription(string $description): string {
    $without_urls = preg_replace(
      '~\b(?:(?:https?://|www\.)[^\s<>()]+|(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}(?:/[^\s<>()]*)?)~iu',
      '',
      $description,
    );

    // Evita spazi lasciati dall'URL rimosso a fine o in mezzo al testo.
    return trim((string) preg_replace('/[ \t]{2,}/', ' ', $without_urls ?? $description));
  }

  /**
   * Aggiunge al testo del post l'indicazione del commento con il link.
   */
  private function buildFacebookMessage(string $description): string {
    return $description . "\nLink all'evento nel primo commento 👇";
  }

  private function reportSkip(NodeInterface $event, OutputInterface $output, string $reason): void {
    $this->logger()->warning('Evento @nid saltato: @reason.', [
      '@nid' => $event->id(),
      '@reason' => $reason,
    ]);
    $output->writeln(sprintf('<comment>Evento %d saltato: %s.</comment>', $event->id(), $reason));
  }

  private function logger(): \Psr\Log\LoggerInterface {
    return \Drupal::logger('ildeposito_utils');
  }

  /**
   * @return \Drupal\node\NodeInterface[]
   *   Eventi pubblicati il cui anniversario cade oggi, ordinati per titolo.
   */
  private function getEventiAnniversarioOggi(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $month_day = (new DrupalDateTime('now'))->format('m-d');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'evento')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_data_evento', '-' . $month_day, 'ENDS_WITH')
      ->sort('title', 'ASC')
      ->execute();

    if ($ids === []) {
      return [];
    }

    $nodes = $storage->loadMultiple($ids);
    $events = [];
    foreach ($ids as $id) {
      if (isset($nodes[$id])) {
        $events[] = $nodes[$id];
      }
    }

    return $events;
  }

}
