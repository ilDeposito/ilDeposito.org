<?php

declare(strict_types=1);

namespace Drupal\ildeposito_stats\Hook;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ildeposito_stats\Drush\Commands\UmamiSyncCommand;

final class IldepositoStatsHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly StateInterface $state,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Mostra in admin/reports/status data/ora dell'ultimo ildeposito:umami-sync
   * riuscito, letta dallo stesso state aggiornato dal comando ad ogni run.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $lastSync = $this->state->get(UmamiSyncCommand::STATE_LAST_SYNC);

    return [
      'ildeposito_stats_last_sync' => [
        'title' => $this->t('Aggiornamento statistiche'),
        'value' => $lastSync
          ? $this->dateFormatter->format((int) ($lastSync / 1000), 'custom', 'd/m/Y - H:i')
          : $this->t('Non ancora eseguito'),
        'severity' => $lastSync ? RequirementSeverity::OK : RequirementSeverity::Warning,
      ],
    ];
  }

}
