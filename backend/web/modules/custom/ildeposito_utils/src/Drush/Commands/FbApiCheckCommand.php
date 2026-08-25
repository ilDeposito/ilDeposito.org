<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Drush\Commands;

use Drupal\ildeposito_utils\Service\FacebookPageClient;
use Drush\Commands\AutowireTrait;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Verifica l'accesso alla Graph API necessario alle pubblicazioni Facebook.
 *
 * La chiamata replica il passaggio preliminare eseguito dalle pubblicazioni:
 * il token del System User deve poter leggere il token della Pagina. Non crea,
 * modifica o pubblica alcuna risorsa Facebook.
 */
#[AsCommand(
  name: self::NAME,
  description: 'Verifica l’accesso Graph API di Facebook usato per le pubblicazioni della Pagina.',
  aliases: ['iufbapicheck'],
)]
final class FbApiCheckCommand extends Command {

  use AutowireTrait;

  public const NAME = 'ildeposito:fb-api-check';

  public function __construct(
    private readonly FacebookPageClient $facebookPageClient,
  ) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    if (!$this->facebookPageClient->isConfigured()) {
      return $this->reportFailure($output, 'configurazione assente (servono FB_PAGE_ID e FB_SYSTEM_USER_TOKEN)');
    }

    try {
      // Controlla esattamente la richiesta necessaria per derivare il token
      // della Pagina prima dell'upload di foto, post o commenti.
      $response = $this->facebookPageClient->get(
        $this->facebookPageClient->getPageId(),
        ['fields' => 'access_token'],
      );
    }
    catch (\Throwable $exception) {
      return $this->reportFailure($output, $this->getSafeErrorMessage($exception));
    }

    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      return $this->reportFailure($output, sprintf('risposta HTTP inattesa: %d', $response->getStatusCode()));
    }

    $output->writeln('<info>Facebook Graph API raggiungibile: accesso alla Pagina verificato.</info>');
    return Command::SUCCESS;
  }

  /**
   * Registra un errore Drupal, intercettato dal notifier Telegram in prod.
   */
  private function reportFailure(OutputInterface $output, string $reason): int {
    $message = 'Controllo Facebook Graph API fallito: ' . $reason;
    \Drupal::logger('ildeposito_utils')->error($message);
    $output->writeln('<error>' . $message . '</error>');
    return Command::FAILURE;
  }

  /**
   * Produce un messaggio diagnostico privo di URL o parametri segreti.
   */
  private function getSafeErrorMessage(\Throwable $exception): string {
    if ($exception instanceof RequestException && $exception->getResponse() !== NULL) {
      $response = $exception->getResponse();
      try {
        $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException) {
        $payload = [];
      }

      $error = is_array($payload) ? ($payload['error'] ?? []) : [];
      if (is_array($error) && isset($error['message'])) {
        $code = isset($error['code']) ? sprintf(' (codice %s)', $error['code']) : '';
        return sprintf('Facebook ha risposto HTTP %d: %s%s', $response->getStatusCode(), $error['message'], $code);
      }

      return sprintf('Facebook ha risposto HTTP %d.', $response->getStatusCode());
    }

    // Le eccezioni di trasporto possono includere URL e query string: non
    // propaghiamole mai nel log, che in produzione viene inoltrato a Telegram.
    return sprintf('errore interno durante la richiesta (%s)', $exception::class);
  }

}
