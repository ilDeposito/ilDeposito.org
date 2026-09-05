<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Drush\Commands;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\ildeposito_utils\Service\FacebookPageClient;
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
 * Per ogni evento con anniversario oggi crea una foto programmata, usando
 * l'immagine dell'evento e field_descrizione_social come didascalia.
 */
#[AsCommand(
  name: self::NAME,
  description: 'Programma foto, descrizione e URL dell’evento per gli eventi con anniversario oggi.',
  aliases: ['iufbeventigiorno'],
)]
final class FbEventiGiornoCommand extends Command {

  use AutowireTrait;

  public const NAME = 'ildeposito:fb-eventigiorno';

  // Le fasce definiscono soltanto l'ora. Ogni pubblicazione riceve un minuto
  // casuale tra 01 e 29, per non concentrare i post all'inizio dell'ora.
  private const HOURS_SCHEDULE = [
    1 => ['7'],
    2 => ['7', '13'],
    3 => ['7', '13', '17'],
    4 => ['7', '12', '16', '18'],
  ];
  private const HOURS_SCHEDULE_DEFAULT = ['7', '9', '11', '13', '15', '17', '19'];

  /**
   * Inviti alternativi alla lettura, scelti casualmente per variare le
   * didascalie pubblicate sulla Pagina.
   */
  private const CALL_TO_ACTIONS = [
    '🎵 Leggi la scheda dell\'evento e scopri i canti collegati:',
    '🎶 Scopri i canti legati a questo evento e leggi la scheda completa:',
    '📖 Approfondisci l\'evento e scopri i canti collegati:',
    '🔎 Scopri la storia dell\'evento e i canti ad esso collegati:',
    '🎵 Scopri i canti collegati e approfondisci l\'evento:',
    '📚 Leggi la scheda completa e scopri i canti legati all\'evento:',
    '🎶 Scopri quali canti sono legati a questo evento:',
    '🔗 Approfondisci l\'evento e consulta i canti collegati:',
    '🎼 Esplora i canti collegati a questo evento e la sua storia:',
    '👉 Scopri la scheda dell\'evento e i canti collegati:',
  ];

  // Meta richiede che scheduled_publish_time sia almeno dieci minuti nel
  // futuro. Il margine evita chiamate destinate a fallire se il comando viene
  // lanciato a ridosso della prima fascia.
  private const MINIMUM_SCHEDULE_DELAY = 600;

  // In CLI Drush non esiste un request context affidabile: il link deve
  // puntare al frontend pubblico, non all'host backend Drupal.
  private const PUBLIC_BASE_URL = 'https://www.ildeposito.org';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FacebookPageClient $facebookPageClient,
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
      'test-immediate',
      NULL,
      InputOption::VALUE_NONE,
      'TEST TEMPORANEO: pubblica subito soltanto il primo evento del giorno.',
    );
    $this->addOption(
      'date',
      NULL,
      InputOption::VALUE_REQUIRED,
      'Solo con --dry-run: simula la pubblicazione per la data YYYY-MM-DD.',
    );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $dry_run = (bool) $input->getOption('dry-run');
    $test_immediate = (bool) $input->getOption('test-immediate');
    $reference_date = $this->getReferenceDate((string) $input->getOption('date'), $dry_run, $output);
    if (!$reference_date instanceof DrupalDateTime) {
      return Command::FAILURE;
    }
    if (!$dry_run && !$this->facebookPageClient->isConfigured()) {
      $output->writeln('<comment>Facebook Graph API non configurata (servono FB_PAGE_ID e FB_SYSTEM_USER_TOKEN): comando disattivato in questo ambiente.</comment>');
      return Command::SUCCESS;
    }

    if ($test_immediate) {
      return $this->executeImmediateTest($dry_run, $output, $reference_date);
    }

    $events = $this->getEventiAnniversarioOggi($reference_date);
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

      $minute = random_int(1, 29);
      $scheduled_at = new DrupalDateTime(sprintf('%s %s:%02d:00', $reference_date->format('Y-m-d'), $hour, $minute));
      if ($scheduled_at->getTimestamp() < $now->getTimestamp() + self::MINIMUM_SCHEDULE_DELAY) {
        $this->reportSkip($event, $output, 'fascia oraria già trascorsa o troppo vicina');
        continue;
      }

      $description = trim((string) $event->get('field_descrizione_social')->value);
      if ($description === '') {
        $this->reportSkip($event, $output, 'field_descrizione_social vuoto');
        continue;
      }

      $link = Url::fromRoute('entity.node.canonical', ['node' => $event->id()], [
        'absolute' => TRUE,
        'base_url' => self::PUBLIC_BASE_URL,
      ])->toString();
      $image = $this->getEventImage($event);
      if (!$image instanceof FileInterface) {
        $this->reportSkip($event, $output, 'immagine dell’evento assente o non valida');
        continue;
      }
      $description = $this->removeUrlsFromDescription($description);
      if ($description === '') {
        $this->reportSkip($event, $output, 'field_descrizione_social contiene soltanto URL');
        continue;
      }
      $message = $this->buildFacebookMessage($description, $link);

      if ($dry_run) {
        $scheduled++;
        $output->writeln(sprintf('<info>[dry-run] Evento %d: foto + testo alle %s</info>', $event->id(), $scheduled_at->format('H:i')));
        $output->writeln($message);
        continue;
      }

      try {
        $this->schedulePhotoPost($message, $image, $scheduled_at->getTimestamp());
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
   * Pubblica subito la foto del primo evento del giorno per verificare l'integrazione.
   */
  private function executeImmediateTest(bool $dry_run, OutputInterface $output, DrupalDateTime $reference_date): int {
    $events = $this->getEventiAnniversarioOggi($reference_date);
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

    $link = Url::fromRoute('entity.node.canonical', ['node' => $event->id()], [
      'absolute' => TRUE,
      'base_url' => self::PUBLIC_BASE_URL,
    ])->toString();
    $image = $this->getEventImage($event);
    if (!$image instanceof FileInterface) {
      $this->reportSkip($event, $output, 'immagine dell’evento assente o non valida');
      return Command::FAILURE;
    }
    $message = $this->buildFacebookMessage($description, $link);
    if ($dry_run) {
      $output->writeln(sprintf('<info>[dry-run] Test evento %d: pubblicazione immediata della foto</info>', $event->id()));
      return Command::SUCCESS;
    }

    try {
      $this->publishPhotoImmediately($message, $image);
    }
    catch (\Throwable $exception) {
      $this->logger()->error('Test pubblicazione Facebook fallito per evento @nid: @message', [
        '@nid' => $event->id(),
        '@message' => $exception->getMessage(),
      ]);
      $output->writeln(sprintf('<error>Test evento %d: pubblicazione Facebook fallita (%s).</error>', $event->id(), $exception->getMessage()));
      return Command::FAILURE;
    }

    $output->writeln(sprintf('<info>Test evento %d pubblicato subito su Facebook.</info>', $event->id()));
    return Command::SUCCESS;
  }

  /**
   * Pubblica subito una foto della Pagina.
   */
  private function publishPhotoImmediately(string $description, FileInterface $image): void {
    $this->postPhoto($description, $image);
  }

  /**
   * Crea una foto della Pagina e la programma all'orario indicato.
   */
  private function schedulePhotoPost(string $description, FileInterface $image, int $scheduled_publish_time): void {
    $this->postPhoto($description, $image, $scheduled_publish_time);
  }

  /**
   * Carica una foto nell'edge della Pagina, immediatamente o programmata.
   */
  private function postPhoto(string $description, FileInterface $image, ?int $scheduled_publish_time = NULL): void {
    $file = fopen($image->getFileUri(), 'rb');
    if ($file === FALSE) {
      throw new \RuntimeException(sprintf('Impossibile leggere il file immagine %d.', $image->id()));
    }

    $multipart = [
      ['name' => 'caption', 'contents' => $description],
      ['name' => 'source', 'contents' => $file, 'filename' => $image->getFilename()],
    ];
    if ($scheduled_publish_time !== NULL) {
      $multipart[] = ['name' => 'published', 'contents' => 'false'];
      $multipart[] = ['name' => 'scheduled_publish_time', 'contents' => (string) $scheduled_publish_time];
    }

    try {
      $this->facebookPageClient->postMultipartToPage('photos', $multipart);
    }
    finally {
      fclose($file);
    }
  }

  /**
   * Rimuove dal post principale tutti gli URL, inclusi quelli Bitly.
   *
   * L'URL canonico del nodo viene inviato separatamente nel parametro link.
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
   * Formatta l'etichetta iniziale e aggiunge l'invito alla lettura della card.
   */
  private function buildFacebookMessage(string $description, string $link): string {
    $description = preg_replace('/^\[[^\]]+\]\h*/u', '📅 ', $description) ?? $description;
    $call_to_action = self::CALL_TO_ACTIONS[random_int(0, count(self::CALL_TO_ACTIONS) - 1)];

    return $description . "\n" . $call_to_action . ' ' . $link;
  }

  /**
   * Restituisce il file dell'immagine Media associata all'evento.
   */
  private function getEventImage(NodeInterface $event): ?FileInterface {
    $media = $event->get('field_immagine')->entity;
    if ($media === NULL || !$media->hasField('field_media_image')) {
      return NULL;
    }

    $image = $media->get('field_media_image')->entity;
    return $image instanceof FileInterface ? $image : NULL;
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
  private function getEventiAnniversarioOggi(DrupalDateTime $reference_date): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $month_day = $reference_date->format('m-d');

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

  /**
   * Restituisce la data della simulazione, oppure il giorno corrente.
   */
  private function getReferenceDate(string $date_option, bool $dry_run, OutputInterface $output): ?DrupalDateTime {
    $date_option = trim($date_option);
    if ($date_option === '') {
      return new DrupalDateTime('now');
    }
    if (!$dry_run) {
      $output->writeln('<error>L’opzione --date è consentita solo insieme a --dry-run.</error>');
      return NULL;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_option)) {
      $output->writeln('<error>La data deve avere il formato YYYY-MM-DD.</error>');
      return NULL;
    }

    try {
      $reference_date = new DrupalDateTime($date_option);
    }
    catch (\Exception) {
      $output->writeln('<error>La data indicata non è valida.</error>');
      return NULL;
    }
    if ($reference_date->format('Y-m-d') !== $date_option) {
      $output->writeln('<error>La data indicata non è valida.</error>');
      return NULL;
    }

    return $reference_date;
  }

}
