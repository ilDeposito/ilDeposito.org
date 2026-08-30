<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Drush\Commands;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
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
 * Per ogni evento con anniversario oggi crea un post-link programmato, usando
 * field_descrizione_social come testo e l'URL pubblico del nodo come link.
 */
#[AsCommand(
  name: self::NAME,
  description: 'Programma descrizione e link dell’evento per gli eventi con anniversario oggi.',
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
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $dry_run = (bool) $input->getOption('dry-run');
    $test_immediate = (bool) $input->getOption('test-immediate');
    if (!$dry_run && !$this->facebookPageClient->isConfigured()) {
      $output->writeln('<comment>Facebook Graph API non configurata (servono FB_PAGE_ID e FB_SYSTEM_USER_TOKEN): comando disattivato in questo ambiente.</comment>');
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

      $link = Url::fromRoute('entity.node.canonical', ['node' => $event->id()], [
        'absolute' => TRUE,
        'base_url' => self::PUBLIC_BASE_URL,
      ])->toString();
      $description = $this->removeUrlsFromDescription($description);
      if ($description === '') {
        $this->reportSkip($event, $output, 'field_descrizione_social contiene soltanto URL');
        continue;
      }

      if ($dry_run) {
        $scheduled++;
        $output->writeln(sprintf('<info>[dry-run] Evento %d: testo + link %s alle %s</info>', $event->id(), $link, $scheduled_at->format('H:i')));
        continue;
      }

      try {
        $this->scheduleLinkPost($description, $link, $scheduled_at->getTimestamp());
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

    $link = Url::fromRoute('entity.node.canonical', ['node' => $event->id()], [
      'absolute' => TRUE,
      'base_url' => self::PUBLIC_BASE_URL,
    ])->toString();
    if ($dry_run) {
      $output->writeln(sprintf('<info>[dry-run] Test evento %d: pubblicazione immediata con link %s</info>', $event->id(), $link));
      return Command::SUCCESS;
    }

    try {
      $this->publishLinkImmediately($description, $link);
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
   * Pubblica subito un post-link della Pagina.
   */
  private function publishLinkImmediately(string $description, string $link): void {
    $this->facebookPageClient->postToPage('feed', [
      'message' => $description,
      'link' => $link,
    ]);
  }

  /**
   * Crea un post-link della Pagina e lo programma all'orario indicato.
   */
  private function scheduleLinkPost(string $description, string $link, int $scheduled_publish_time): void {
    $this->facebookPageClient->postToPage('feed', [
      'message' => $description,
      'link' => $link,
      'published' => 'false',
      'scheduled_publish_time' => (string) $scheduled_publish_time,
    ]);
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
