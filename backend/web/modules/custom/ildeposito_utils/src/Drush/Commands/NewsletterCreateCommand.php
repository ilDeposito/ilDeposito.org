<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Drush\Commands;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\node\NodeInterface;
use Drush\Commands\AutowireTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Crea una campagna newsletter su Listmonk (stato bozza).
 *
 * La campagna usa la lista #4 e il template #9. Nome e oggetto riflettono la
 * data corrente in italiano ("Newsletter 18 settembre 2026").
 *
 * Il body contiene la raccolta settimanale:
 * - "Storia cantata: gli eventi della settimana": gli eventi il cui
 *   anniversario (mese/giorno di field_data_evento) cade tra oggi e i 6
 *   giorni successivi (7 giorni, la settimana lun>dom di pubblicazione),
 *   con la data dell'evento in italiano davanti al titolo
 *   ("28 ottobre 1945 - Titolo");
 * - "Ultimi canti inseriti": gli ultimi 5 canti pubblicati.
 * I link usano {{ TrackLink }} per il tracking Listmonk e hanno CSS inline
 * (nero, sottolineato). Se non cade alcun evento, il blocco evento sparisce.
 * Subito dopo la creazione la bozza viene validata chiamando la preview
 * dell'API Listmonk: se il contenuto non compila il comando fallisce (in
 * stage/prod l'errore arriva su Telegram), lasciando comunque la bozza.
 *
 * Le credenziali API (LISTMONK_BASE_URL, LISTMONK_USERNAME, LISTMONK_TOKEN)
 * sono lette dal file .env della root del progetto via settings.php. Se
 * assenti il comando è un no-op, così stage/DDEV non creano campagne.
 */
#[AsCommand(
  name: self::NAME,
  description: 'Crea la campagna newsletter su Listmonk (bozza, lista 4, template 9).',
  aliases: ['iuneventcreate'],
)]
final class NewsletterCreateCommand extends Command {

  use AutowireTrait;

  public const NAME = 'ildeposito:newsletter-create';

  // Lista e template fissi per questa newsletter.
  private const LIST_ID = 4;
  private const TEMPLATE_ID = 9;

  // Frontend pubblico: il backend (e drush) non ha request context, l'URL
  // deve restare fisso e puntare sempre al sito pubblico.
  private const PUBLIC_BASE_URL = 'https://www.ildeposito.org';

  private const FROM_NAME = 'ilDeposito.org';
  private const FROM_EMAIL = 'noreply@mail.ildeposito.org';
  private const REPLY_TO_EMAIL = 'info@ildeposito.org';

  // Ampiezza della settimana di pubblicazione (lunedì + 6 giorni).
  private const SETTIMANA_GIORNI = 7;

  // Numero di canti mostrati nel blocco "Ultimi canti inseriti".
  private const ULTIMI_CANTI_COUNT = 5;

  // Finestra temporale (giorni) che definisce i canti "appena inseriti".
  private const CANTI_ULTIMI_GIORNI = 7;

  // Fase di sviluppo: il blocco "Ultimi canti inseriti" (titolo compreso) è
  // sempre mostrato. In produzione impostare a TRUE: il blocco apparirà solo
  // se negli ultimi CANTI_ULTIMI_GIORNI giorni sono stati pubblicati canti,
  // altrimenti sparisce del tutto.
  private const SHOW_CANTI_SOLO_ULTIMI_GIORNI = FALSE;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $baseUrl = $this->getBaseUrl();
    $username = $this->getUsername();
    $token = $this->getToken();

    if ($baseUrl === '' || $username === '' || $token === '') {
      $output->writeln('<comment>Configurazione Listmonk assente (LISTMONK_BASE_URL/LISTMONK_USERNAME/LISTMONK_TOKEN): comando disattivato in questo ambiente.</comment>');
      return Command::SUCCESS;
    }

    if (!$this->isValidBaseUrl($baseUrl)) {
      return $this->reportFailure($output, 'LISTMONK_BASE_URL deve essere un URL HTTPS valido.');
    }

    // Data corrente in italiano ("18 settembre 2026"): IntlDateFormatter usa
    // locale "it_IT" e fuso del sito (Europe/Rome, impostato da Drupal).
    $dateIt = (new \IntlDateFormatter('it_IT', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, date_default_timezone_get()))->format(time());
    $name = 'Newsletter ' . $dateIt;
    $subject = '[ilDeposito] Newsletter ' . $dateIt;

    $body = $this->buildBody();

    $payload = [
      'name' => $name,
      'subject' => $subject,
      'lists' => [self::LIST_ID],
      'type' => 'regular',
      'content_type' => 'html',
      'body' => $body['html'],
      'altbody' => $body['text'],
      'from_email' => self::FROM_NAME . ' <' . self::FROM_EMAIL . '>',
      'headers' => [['Reply-To' => self::REPLY_TO_EMAIL]],
      'messenger' => 'email',
      'template_id' => self::TEMPLATE_ID,
      'tags' => ['newsletter'],
    ];

    try {
      // Basic Auth come da API Listmonk: l'utente API è username:token.
      $response = $this->httpClient->request('POST', $baseUrl . '/api/campaigns', [
        'headers' => [
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
          'Authorization' => 'Basic ' . base64_encode($username . ':' . $token),
        ],
        'json' => $payload,
        'connect_timeout' => 10,
        'timeout' => 30,
      ]);
    }
    catch (\Throwable $e) {
      return $this->reportFailure($output, $this->getSafeErrorMessage($e));
    }

    $campaignId = $this->extractCampaignId($response->getBody());

    if ($campaignId !== NULL) {
      \Drupal::logger('ildeposito_utils')->info('Campagna Listmonk creata: @name (#@id).', ['@name' => $name, '@id' => $campaignId]);
      $output->writeln(sprintf('<info>Campagna creata: %s (#%d).</info>', $name, $campaignId));

      if ($this->validatePreview($campaignId)) {
        $output->writeln(sprintf('<info>Preview campagna #%d: OK (contenuto compilabile).</info>', $campaignId));
      }
      else {
        $message = sprintf('Campagna #%d creata, ma la preview Listmonk non è riuscita: il contenuto non compila (rivedere la bozza su newsletter.ildeposito.org).', $campaignId);
        \Drupal::logger('ildeposito_utils')->error($message);
        $output->writeln('<error>' . $message . '</error>');
        return Command::FAILURE;
      }
    }
    else {
      \Drupal::logger('ildeposito_utils')->info('Campagna Listmonk creata: @name (ID non presente nella risposta).', ['@name' => $name]);
      $output->writeln(sprintf('<info>Campagna creata: %s (ID non presente nella risposta).</info>', $name));
    }

    return Command::SUCCESS;
  }

  /**
   * Compone intro e blocchi della newsletter in HTML e in testo semplice.
   *
   * @return array{html: string, text: string}
   */
  private function buildBody(): array {
    $htmlParts = [];
    $textParts = [];

    $htmlParts[] = '<p>Ecco la newsletter settimanale de ilDeposito.org: storia cantata, nuovi inserimenti e altre notizie.</p>';
    $textParts[] = 'Ecco la newsletter settimanale de ilDeposito.org: storia cantata, nuovi inserimenti e altre notizie.';

    $eventi = $this->getEventiAnniversarioSettimana();
    if ($eventi !== []) {
      $htmlParts[] = '<h2>Storia cantata: gli eventi della settimana</h2>';
      $htmlParts[] = $this->buildHtmlList($eventi, TRUE);
      $textParts[] = '';
      $textParts[] = 'Storia cantata: gli eventi della settimana';
      $textParts[] = $this->buildTextList($eventi, TRUE);
    }

    $canti = $this->getUltimiCanti();
    if ($canti !== []) {
      $htmlParts[] = '<h2>Ultimi canti inseriti</h2>';
      $htmlParts[] = $this->buildHtmlList($canti);
      $textParts[] = '';
      $textParts[] = 'Ultimi canti inseriti';
      $textParts[] = $this->buildTextList($canti);
    }

    return [
      'html' => implode("\n", $htmlParts),
      'text' => implode("\n", $textParts),
    ];
  }

  /**
   * @return \Drupal\node\NodeInterface[] Eventi pubblicati il cui anniversario
   *   (mese/giorno di field_data_evento) cade nei prossimi SETTIMANA_GIORNI
   *   giorni, ordinati per giorno della settimana e poi per titolo.
   */
  private function getEventiAnniversarioSettimana(): array {
    $start = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
    $monthDays = [];
    for ($i = 0; $i < self::SETTIMANA_GIORNI; $i++) {
      $monthDays[] = '-' . $start->modify('+' . $i . ' day')->format('m-d');
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'evento')
      ->condition('status', NodeInterface::PUBLISHED);
    $or = $query->orConditionGroup();
    foreach ($monthDays as $monthDay) {
      $or->condition('field_data_evento', $monthDay, 'ENDS_WITH');
    }
    $ids = $query->condition($or)->execute();

    if ($ids === []) {
      return [];
    }

    $nodes = $storage->loadMultiple($ids);

    $dayIndex = [];
    foreach ($monthDays as $i => $monthDay) {
      $dayIndex[$monthDay] = $i;
    }

    $events = [];
    foreach ($nodes as $node) {
      $monthDay = '-' . substr((string) $node->get('field_data_evento')->value, 5, 5);
      if (!isset($dayIndex[$monthDay])) {
        continue;
      }
      $events[] = [$dayIndex[$monthDay], $node];
    }

    usort($events, static fn (array $a, array $b): int => [$a[0], mb_strtolower($a[1]->label())] <=> [$b[0], mb_strtolower($b[1]->label())]);

    return array_map(static fn (array $entry): NodeInterface => $entry[1], $events);
  }

  /**
   * @return \Drupal\node\NodeInterface[] Gli ultimi ULTIMI_CANTI_COUNT canti
   *   pubblicati. In produzione (SHOW_CANTI_SOLO_ULTIMI_GIORNI=TRUE) vengono
   *   considerati solo i canti pubblicati negli ultimi CANTI_ULTIMI_GIORNI
   *   giorni, così il blocco può sparire se non ce ne sono.
   */
  private function getUltimiCanti(): array {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'canto')
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('created', 'DESC');

    if (self::SHOW_CANTI_SOLO_ULTIMI_GIORNI) {
      $query->condition('created', \Drupal::time()->getRequestTime() - self::CANTI_ULTIMI_GIORNI * 86400, '>=');
    }

    $ids = $query->range(0, self::ULTIMI_CANTI_COUNT)->execute();

    return $ids === [] ? [] : $this->entityTypeManager->getStorage('node')->loadMultiple($ids);
  }

  /**
   * @param \Drupal\node\NodeInterface[] $nodes
   * @param bool $withDate
   *   TRUE per gli eventi: ogni voce è prefissata con la data dell'evento in
   *   italiano ("28 ottobre 1945 - Titolo").
   *
   *   Elenco puntato HTML con link assoluti tracciati via {{ TrackLink }}:
   *   CSS inline nero e sottolineato.
   */
  private function buildHtmlList(array $nodes, bool $withDate = FALSE): string {
    $items = '';
    foreach ($nodes as $node) {
      $label = $node->label();
      if ($withDate) {
        $dateLabel = $this->eventDateLabel($node);
        if ($dateLabel !== '') {
          $label = $dateLabel . ' - ' . $label;
        }
      }
      $url = $node->toUrl('canonical', [
        'absolute' => TRUE,
        'base_url' => self::PUBLIC_BASE_URL,
      ])->toString();
      $items .= '<li><a href="{{ TrackLink "' . $url . '" }}" style="color:#000000;text-decoration:underline">' . Html::escape($label) . '</a></li>';
    }

    return '<ul>' . $items . '</ul>';
  }

  /**
   * @param \Drupal\node\NodeInterface[] $nodes
   * @param bool $withDate
   *   TRUE per gli eventi: ogni voce è prefissata con la data dell'evento in
   *   italiano.
   *
   *   Stessa lista in testo semplice (per l'altbody, senza tracking).
   */
  private function buildTextList(array $nodes, bool $withDate = FALSE): string {
    $items = [];
    foreach ($nodes as $node) {
      $label = $node->label();
      if ($withDate) {
        $dateLabel = $this->eventDateLabel($node);
        if ($dateLabel !== '') {
          $label = $dateLabel . ' - ' . $label;
        }
      }
      $url = $node->toUrl('canonical', [
        'absolute' => TRUE,
        'base_url' => self::PUBLIC_BASE_URL,
      ])->toString();
      $items[] = '- ' . $label . ': ' . $url;
    }

    return implode("\n", $items);
  }

  /**
   * Data dell'evento (field_data_evento) in italiano, es. "28 ottobre 1945".
   *
   * @return string
   *   Stringa vuota se il campo non è leggibile.
   */
  private function eventDateLabel(NodeInterface $node): string {
    $value = $node->get('field_data_evento')->value;
    if (empty($value)) {
      return '';
    }

    $timezone = date_default_timezone_get();
    $date = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $value, new \DateTimeZone($timezone));
    if ($date === FALSE) {
      return '';
    }

    $formatter = new \IntlDateFormatter('it_IT', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, $timezone);

    return (string) $formatter->format($date);
  }

  /**
   * Estrae l'ID della campagna dalla risposta di Listmonk.
   */
  private function extractCampaignId($body): ?int {
    try {
      $payload = json_decode((string) $body, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }

    $id = $payload['data']['id'] ?? NULL;

    return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : NULL;
  }

  /**
   * Verifica che la campagna appena creata sia rendibilizzabile: la GET
   * /api/campaigns/{id}/preview compila il body (inclusi i {{ TrackLink }})
   * con il template assegnato e risponde con un errore HTTP se il contenuto
   * non compila. Fallisce solo il render: la bozza rimane comunque creata.
   *
   * @param int $campaignId
   *   ID della campagna appena creata.
   *
   * @return bool
   *   TRUE se la preview viene servita correttamente.
   */
  private function validatePreview(int $campaignId): bool {
    try {
      $response = $this->httpClient->request('GET', $this->getBaseUrl() . '/api/campaigns/' . $campaignId . '/preview', [
        'headers' => [
          'Accept' => 'application/json',
          'Authorization' => 'Basic ' . base64_encode($this->getUsername() . ':' . $this->getToken()),
        ],
        'connect_timeout' => 10,
        'timeout' => 30,
      ]);
    }
    catch (\Throwable $e) {
      \Drupal::logger('ildeposito_utils')->error('Preview Listmonk non riuscita per la campagna @id: @error', [
        '@id' => $campaignId,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }

    return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
  }

  private function reportFailure(OutputInterface $output, string $reason): int {
    $message = 'Creazione campagna Listmonk fallita: ' . $reason;
    \Drupal::logger('ildeposito_utils')->error($message);
    $output->writeln('<error>' . $message . '</error>');
    return Command::FAILURE;
  }

  private function isValidBaseUrl(string $baseUrl): bool {
    $parts = parse_url($baseUrl);

    return $parts !== FALSE
      && ($parts['scheme'] ?? '') === 'https'
      && !empty($parts['host'])
      && !isset($parts['user'])
      && !isset($parts['pass'])
      && !isset($parts['query'])
      && !isset($parts['fragment']);
  }

  /**
   * Evita di scrivere URL completi o token in output/log.
   */
  private function getSafeErrorMessage(\Throwable $e): string {
    if ($e instanceof RequestException && $e->getResponse() !== NULL) {
      return sprintf('Listmonk ha risposto HTTP %d.', $e->getResponse()->getStatusCode());
    }

    return sprintf('errore interno durante la richiesta (%s)', $e::class);
  }

  private function getBaseUrl(): string {
    return rtrim(trim((string) Settings::get('ildeposito_utils_listmonk_base_url', '')), '/');
  }

  private function getUsername(): string {
    return trim((string) Settings::get('ildeposito_utils_listmonk_username', ''));
  }

  private function getToken(): string {
    return trim((string) Settings::get('ildeposito_utils_listmonk_token', ''));
  }

}