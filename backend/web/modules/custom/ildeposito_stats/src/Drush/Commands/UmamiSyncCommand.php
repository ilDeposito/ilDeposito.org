<?php

declare(strict_types=1);

namespace Drupal\ildeposito_stats\Drush\Commands;

use Drupal\Core\State\StateInterface;
use Drupal\ildeposito_stats\Service\EntityUrlMatcher;
use Drupal\ildeposito_stats\Service\UmamiClient;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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

  private const STATE_LAST_SYNC = 'ildeposito_stats.last_sync';
  private const STATE_LAST_ACTIVE = 'ildeposito_stats.last_active_entities';

  private const HOUR_MS = 3600 * 1000;

  public function __construct(
    private readonly UmamiClient $umamiClient,
    private readonly EntityUrlMatcher $entityUrlMatcher,
    private readonly StateInterface $state,
  ) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    if (!$this->umamiClient->isConfigured()) {
      $output->writeln('<error>Umami non configurato: impostare UMAMI_API_URL, UMAMI_USERNAME, UMAMI_PASSWORD, UMAMI_WEBSITE_ID.</error>');
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

    // Scrittura disattivata temporaneamente su richiesta: il comando calcola
    // e stampa comunque tutto, ma non tocca i nodi né lo State, così da poter
    // riabilitarla in seguito senza aver perso il delta di questo periodo
    // (il watermark NON avanza finché il blocco sotto resta commentato).
    /*
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
    */

    $output->writeln(sprintf(
      '<comment>[scrittura disattivata] %d contenuti da aggiornare — delta dal %s (%d URL matchate); snapshot 6h: %d, 24h: %d, settimana: %d.</comment>',
      count($entities),
      date('Y-m-d H:i:s', (int) ($watermark / 1000)),
      count($delta),
      count($ultime6Ore),
      count($ultime24Ore),
      count($ultimaSettimana),
    ));

    return Command::SUCCESS;
  }

}
