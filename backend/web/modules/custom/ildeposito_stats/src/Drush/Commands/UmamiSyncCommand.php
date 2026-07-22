<?php

declare(strict_types=1);

namespace Drupal\ildeposito_stats\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ildeposito_stats\Service\EntityUrlMatcher;
use Drupal\ildeposito_stats\Service\UmamiClient;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sincronizza le visite Umami sui contenuti Drupal.
 *
 * field_visualizzazioni_totali è un CONTATORE: somma il delta dall'ultimo
 * sync riuscito (watermark in State), mai sovrascritto.
 * field_visualizzazioni_6ore/_24ore/_settimana sono SNAPSHOT: vengono
 * sempre sovrascritti con la finestra fissa corrente, azzerati se
 * un'entità non ha più visite recenti — nessuno stato da mantenere tra un
 * run e l'altro per queste finestre, sono sempre ricalcolate da zero.
 */
#[AsCommand(
  name: self::NAME,
  description: 'Sincronizza le visite Umami sui contenuti Drupal: somma il delta dall\'ultimo sync su field_visualizzazioni_totali e sovrascrive gli snapshot 6h/24h/settimana.',
  aliases: ['iusync'],
)]
final class UmamiSyncCommand extends Command {

  use AutowireTrait;

  public const NAME = 'ildeposito:umami-sync';

  /**
   * Letta anche da IldepositoStatsHooks::runtimeRequirements() per mostrare
   * data/ora dell'ultima esecuzione in admin/reports/status.
   */
  public const STATE_LAST_SYNC = 'ildeposito_stats.last_sync';

  private const STATE_LAST_ACTIVE = 'ildeposito_stats.last_active_entities';

  private const HOUR_MS = 3600 * 1000;

  /**
   * Campi azzerabili con --azzera. field_visualizzazioni_originali è il
   * contatore storico importato dal sito legacy: non va MAI toccato, né
   * qui né nel sync.
   */
  private const RESET_FIELDS = [
    'field_visualizzazioni_totali',
    'field_visualizzazioni_6ore',
    'field_visualizzazioni_24ore',
    'field_visualizzazioni_settimana',
  ];

  public function __construct(
    private readonly UmamiClient $umamiClient,
    private readonly EntityUrlMatcher $entityUrlMatcher,
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this->addOption(
      'azzera',
      NULL,
      InputOption::VALUE_NONE,
      'Azzera i contatori field_visualizzazioni_totali/6ore/24ore/settimana su tutti i contenuti (field_visualizzazioni_originali non viene toccato) e riparte a contare da adesso. Non esegue il sync.',
    );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    if ($input->getOption('azzera')) {
      return $this->azzera($output);
    }

    if (!$this->umamiClient->isConfigured()) {
      $message = 'Umami non configurato: impostare UMAMI_API_URL, UMAMI_USERNAME, UMAMI_PASSWORD, UMAMI_WEBSITE_ID.';
      $output->writeln("<error>{$message}</error>");
      // Loggato (non solo a console): un cron headless con configurazione
      // mancante altrimenti fallirebbe in silenzio, senza traccia in watchdog.
      $this->logger()->error('Sincronizzazione visite Umami non eseguita: @message', ['@message' => $message]);
      return Command::FAILURE;
    }

    $now = (int) (microtime(TRUE) * 1000);
    $watermark = (int) $this->state->get(self::STATE_LAST_SYNC, $now - 6 * self::HOUR_MS);

    try {
      $delta = $this->entityUrlMatcher->matchAndAggregate(
        $this->umamiClient->getPageviewsByUrlBetween($watermark, $now),
      );
      $ultime6Ore = $this->entityUrlMatcher->matchAndAggregate(
        $this->umamiClient->getPageviewsByUrlBetween($now - 6 * self::HOUR_MS, $now),
      );
      $ultime24Ore = $this->entityUrlMatcher->matchAndAggregate(
        $this->umamiClient->getPageviewsByUrlBetween($now - 24 * self::HOUR_MS, $now),
      );
      $ultimaSettimana = $this->entityUrlMatcher->matchAndAggregate(
        $this->umamiClient->getPageviewsByUrlBetween($now - 7 * 24 * self::HOUR_MS, $now),
      );
    }
    catch (\Throwable $e) {
      $output->writeln(sprintf('<error>Errore nella chiamata a Umami: %s</error>', $e->getMessage()));
      $this->logger()->error('Sincronizzazione visite Umami fallita: @message', ['@message' => $e->getMessage()]);
      return Command::FAILURE;
    }

    $entities = [];
    foreach ([$delta, $ultime6Ore, $ultime24Ore, $ultimaSettimana] as $dataset) {
      foreach ($dataset as $key => $row) {
        $entities[$key] ??= $row['entity'];
      }
    }

    // Entità attive in un run precedente ma assenti da TUTTE le finestre di
    // questo run: vanno comunque ricaricate per azzerare i loro snapshot
    // (altrimenti resterebbero bloccate sull'ultimo valore non-zero per
    // sempre, anche se non hanno più visite da giorni).
    $currentlyActive = array_keys($ultime6Ore + $ultime24Ore + $ultimaSettimana);
    $previouslyActive = (array) $this->state->get(self::STATE_LAST_ACTIVE, []);
    foreach (array_diff($previouslyActive, $currentlyActive) as $key) {
      if (isset($entities[$key])) {
        continue;
      }
      $entity = $this->entityUrlMatcher->loadByKey($key);
      if ($entity) {
        $entities[$key] = $entity;
      }
    }

    foreach ($entities as $key => $entity) {
      if (isset($delta[$key]) && $entity->hasField('field_visualizzazioni_totali')) {
        $totaleAttuale = (int) $entity->get('field_visualizzazioni_totali')->value;
        $entity->set('field_visualizzazioni_totali', $totaleAttuale + $delta[$key]['visite']);
      }
      if ($entity->hasField('field_visualizzazioni_6ore')) {
        $entity->set('field_visualizzazioni_6ore', $ultime6Ore[$key]['visite'] ?? 0);
      }
      if ($entity->hasField('field_visualizzazioni_24ore')) {
        $entity->set('field_visualizzazioni_24ore', $ultime24Ore[$key]['visite'] ?? 0);
      }
      if ($entity->hasField('field_visualizzazioni_settimana')) {
        $entity->set('field_visualizzazioni_settimana', $ultimaSettimana[$key]['visite'] ?? 0);
      }

      // Sync automatico, non una modifica editoriale: niente nuova
      // revisione, e niente bump di "changed" (ChangedItem::preSave() lo
      // salta quando isSyncing() è vero — vedi core/.../ChangedItem.php).
      $entity->setNewRevision(FALSE);
      $entity->setSyncing(TRUE);
      $entity->save();
    }

    $this->state->set(self::STATE_LAST_ACTIVE, array_values($currentlyActive));
    $this->state->set(self::STATE_LAST_SYNC, $now);

    $summary = sprintf(
      '%d contenuti aggiornati — delta dal %s (%d URL matchate); snapshot 6h: %d, 24h: %d, settimana: %d.',
      count($entities),
      date('Y-m-d H:i:s', (int) ($watermark / 1000)),
      count($delta),
      count($ultime6Ore),
      count($ultime24Ore),
      count($ultimaSettimana),
    );
    $output->writeln("<info>{$summary}</info>");
    $this->logger()->info('Sincronizzazione visite Umami completata: @summary', ['@summary' => $summary]);

    return Command::SUCCESS;
  }

  private function logger(): \Psr\Log\LoggerInterface {
    return \Drupal::logger('ildeposito_stats');
  }

  /**
   * Azzera i contatori su nodi e termini e riallinea lo State: il watermark
   * viene portato a adesso, così field_visualizzazioni_totali riparte da
   * zero dal momento dell'azzeramento e le visite già registrate in Umami
   * prima del reset non vengono ri-sommate al sync successivo.
   */
  private function azzera(OutputInterface $output): int {
    $azzerati = 0;

    foreach (['node', 'taxonomy_term'] as $entityTypeId) {
      $storage = $this->entityTypeManager->getStorage($entityTypeId);

      // Solo le entità con almeno un contatore > 0: la query su un campo
      // ne implica l'esistenza sul bundle, quindi il filtro sui bundle
      // giusti è implicito.
      $query = $storage->getQuery()->accessCheck(FALSE);
      $orGroup = $query->orConditionGroup();
      foreach (self::RESET_FIELDS as $field) {
        $orGroup->condition($field, 0, '>');
      }
      $ids = $query->condition($orGroup)->execute();

      foreach ($storage->loadMultiple($ids) as $entity) {
        foreach (self::RESET_FIELDS as $field) {
          if ($entity->hasField($field)) {
            $entity->set($field, 0);
          }
        }

        // Come nel sync: nessuna nuova revisione, nessun bump di "changed".
        $entity->setNewRevision(FALSE);
        $entity->setSyncing(TRUE);
        $entity->save();
        $azzerati++;
      }
    }

    $this->state->set(self::STATE_LAST_SYNC, (int) (microtime(TRUE) * 1000));
    $this->state->delete(self::STATE_LAST_ACTIVE);

    $output->writeln(sprintf(
      '<info>Azzerati i contatori di %d contenuti (field_visualizzazioni_originali non toccato); il conteggio dei totali riparte da adesso.</info>',
      $azzerati,
    ));

    return Command::SUCCESS;
  }

}
