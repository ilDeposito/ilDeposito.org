<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Drush\Commands;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drush\Commands\AutowireTrait;
use GuzzleHttp\ClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Posta su Facebook (via webhook Make.com) gli eventi il cui anniversario
 * cade oggi.
 *
 * Sostituisce il vecchio comando Drush 8 basato sulla vista Search API
 * "eventi_giorno" (rimossa): l'anniversario viene individuato via entity
 * query su field_data_evento (campo "date", formato Y-m-d) confrontando
 * solo mese e giorno.
 */
#[AsCommand(
  name: self::NAME,
  description: 'Posta su Facebook (via webhook Make.com) gli eventi con anniversario oggi, distribuendoli su fasce orarie in base a quanti sono.',
  aliases: ['iufbpost'],
)]
final class FbPostCommand extends Command {

  use AutowireTrait;

  public const NAME = 'ildeposito:fb-post';

  // Fasce orarie in cui distribuire i post nell'arco della giornata, in
  // base al numero di eventi trovati. Oltre le 7 fasce disponibili gli
  // eventi in eccedenza vengono saltati (vedi execute()).
  private const HOURS_SCHEDULE = [
    1 => ['7'],
    2 => ['7', '13'],
    3 => ['7', '13', '17'],
    4 => ['7', '12', '16', '18'],
  ];
  private const HOURS_SCHEDULE_DEFAULT = ['7', '9', '11', '13', '15', '17', '19'];

  // In CLI drush non ha request context: setAbsolute() da solo genera
  // "http://default/..." che Facebook rifiuta ("The url you supplied is
  // invalid", 1500 OAuthException). Il link deve comunque puntare sempre al
  // frontend pubblico, mai all'host backend, quindi la base è fissa e non
  // derivabile dall'ambiente.
  private const PUBLIC_BASE_URL = 'https://www.ildeposito.org';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ClientInterface $httpClient,
  ) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $webhookUrl = (string) Settings::get('ildeposito_utils_fbpost_webhook_url', '');
    if ($webhookUrl === '') {
      $output->writeln('<comment>Webhook FB non configurato (FBPOST_WEBHOOK_URL): comando disattivato in questo ambiente.</comment>');
      return Command::SUCCESS;
    }

    $events = $this->getEventiAnniversarioOggi();
    $number = count($events);
    if ($number === 0) {
      $output->writeln('Nessun evento con anniversario oggi.');
      return Command::SUCCESS;
    }

    $hours = self::HOURS_SCHEDULE[$number] ?? self::HOURS_SCHEDULE_DEFAULT;
    $today = new DrupalDateTime('now');

    $inviati = 0;
    foreach (array_values($events) as $key => $event) {
      $hour = $hours[$key] ?? NULL;
      if ($hour === NULL) {
        $this->logger()->warning('Evento @nid saltato: più eventi del giorno che fasce orarie disponibili.', ['@nid' => $event->id()]);
        $output->writeln(sprintf('<comment>Evento %d saltato: nessuna fascia oraria disponibile.</comment>', $event->id()));
        continue;
      }

      $descrizione = trim((string) $event->get('field_descrizione_social')->value);
      if ($descrizione === '') {
        $this->logger()->warning('Evento @nid saltato: field_descrizione_social vuoto.', ['@nid' => $event->id()]);
        $output->writeln(sprintf('<comment>Evento %d saltato: descrizione social vuota.</comment>', $event->id()));
        continue;
      }

      $link = Url::fromRoute('entity.node.canonical', ['node' => $event->id()], [
        'absolute' => TRUE,
        'base_url' => self::PUBLIC_BASE_URL,
      ])->toString();
      $time = $today->format('Y/m/d') . ' ' . $hour . ':00:00';

      try {
        $this->httpClient->post($webhookUrl, [
          'form_params' => [
            'text' => $descrizione,
            'url' => $link,
            'time' => $time,
          ],
          'timeout' => 15,
        ]);
      }
      catch (\Throwable $e) {
        $this->logger()->error('Invio webhook FB fallito per evento @nid: @message', ['@nid' => $event->id(), '@message' => $e->getMessage()]);
        $output->writeln(sprintf('<error>Evento %d: invio webhook fallito (%s).</error>', $event->id(), $e->getMessage()));
        continue;
      }

      $inviati++;
      $output->writeln(sprintf('<info>Evento %d schedulato alle %s.</info>', $event->id(), $time));
    }

    $output->writeln(sprintf('<info>%d/%d eventi inviati al webhook FB.</info>', $inviati, $number));

    return Command::SUCCESS;
  }

  private function logger(): \Psr\Log\LoggerInterface {
    return \Drupal::logger('ildeposito_utils');
  }

  /**
   * @return \Drupal\node\NodeInterface[] Eventi pubblicati il cui
   *   anniversario (mese/giorno di field_data_evento) cade oggi, ordinati
   *   per titolo.
   */
  private function getEventiAnniversarioOggi(): array {
    $storage = $this->entityTypeManager->getStorage('node');

    $monthDay = (new DrupalDateTime('now'))->format('m-d');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'evento')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_data_evento', '-' . $monthDay, 'ENDS_WITH')
      ->sort('title', 'ASC')
      ->execute();

    if (empty($ids)) {
      return [];
    }

    $nodes = $storage->loadMultiple($ids);

    // loadMultiple() non garantisce di preservare l'ordine di $ids: viene
    // ricostruito qui per rispettare l'ordinamento per titolo della query,
    // da cui dipende l'assegnazione delle fasce orarie.
    $events = [];
    foreach ($ids as $id) {
      if (isset($nodes[$id])) {
        $events[] = $nodes[$id];
      }
    }

    return $events;
  }

}
