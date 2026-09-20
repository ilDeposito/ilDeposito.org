<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Drush\Commands;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
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
 *   giorni successivi (7 giorni, la settimana lun>dom di pubblicazione).
 *   Ogni evento è una riga con miniatura in bianco e nero a sinistra e
 *   data/titolo a destra (image style newsletter_evento, 200×200 reso a
 *   100×100), quando l'evento ha un'immagine;
 * - "Ultimi canti inseriti": gli ultimi 5 canti pubblicati;
 * - "I canti più visti della settimana": i canti con più visualizzazioni nel
 *   campo field_visualizzazioni_settimana.
 * Se una sezione è vuota sparisce; il blocco eventi resta solo se cade un
 * anniversario. I link usano {{ TrackLink }} per il tracking Listmonk e hanno
 * CSS inline (nero, sottolineato). Subito dopo la creazione la bozza viene
 * validata chiamando la preview dell'API Listmonk: se il contenuto non
 * compila il comando fallisce (in stage/prod l'errore arriva su Telegram),
 * lasciando comunque la bozza.
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

  // Numero di canti mostrati nel blocco "I canti più visti della settimana".
  private const CANTI_PIU_VISTI_COUNT = 5;

  // Image style della miniatura dell'evento: crop quadrato B/N (200×200,
  // mostrato a 100×100, così è nitido anche su display Retina).
  private const IMAGE_STYLE_NEWSLETTER_EVENTO = 'newsletter_evento';
  private const EVENTO_THUMB_DISPLAY = 100;
  private const EVENTO_THUMB_SOURCE = 200;

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
      return $this->reportFailure($output, 'LISTMONK_BASE_URL deve essere un URL HTTP/HTTPS valido.');
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

    $htmlParts[] = '<p>Ecco la newsletter settimanale de ilDeposito.org: gli eventi della storia cantata, i nuovi inserimenti e aggiornamento legati al nostro progetto.</p>';
    $textParts[] = 'Ecco la newsletter settimanale de ilDeposito.org: gli eventi della storia cantata, i nuovi inserimenti e aggiornamento legati al nostro progetto..';

    $eventi = $this->getEventiAnniversarioSettimana();
    if ($eventi !== []) {
      $htmlParts[] = '<h3>Storia cantata: gli eventi della settimana</h3>';
      $htmlParts[] = $this->buildEventiHtml($eventi);
      $textParts[] = '';
      $textParts[] = 'Storia cantata: gli eventi della settimana';
      $textParts[] = $this->buildTextList($eventi, TRUE);
    }

    $canti = $this->getUltimiCanti();
    if ($canti !== []) {
      $htmlParts[] = '<h3>Ultimi canti inseriti</h3>';
      $htmlParts[] = $this->buildHtmlList($canti);
      $textParts[] = '';
      $textParts[] = 'Ultimi canti inseriti';
      $textParts[] = $this->buildTextList($canti);
    }

    $popolari = $this->getCantiPiuVistiSettimana();
    if ($popolari !== []) {
      $htmlParts[] = '<h3>I canti più visti della settimana</h3>';
      $htmlParts[] = $this->buildHtmlList($popolari);
      $textParts[] = '';
      $textParts[] = 'I canti più visti della settimana';
      $textParts[] = $this->buildTextList($popolari);
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
   * @return \Drupal\node\NodeInterface[] I canti pubblicati più visualizzati
   *   della settimana (field_visualizzazioni_settimana > 0), dal più visto.
   *   Se nessun canto ha visualizzazioni registrate, ritorna lista vuota e il
   *   blocco non viene stampato.
   */
  private function getCantiPiuVistiSettimana(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'canto')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_visualizzazioni_settimana', 0, '>')
      ->sort('field_visualizzazioni_settimana', 'DESC')
      ->range(0, self::CANTI_PIU_VISTI_COUNT);

    $ids = $query->execute();

    return $ids === [] ? [] : $storage->loadMultiple($ids);
  }

  /**
   * @param \Drupal\node\NodeInterface[] $nodes
   *   Elenco puntato HTML con link assoluti tracciati via {{ TrackLink }}:
   *   CSS inline nero e sottolineato.
   */
  private function buildHtmlList(array $nodes): string {
    $items = '';
    foreach ($nodes as $node) {
      $url = $node->toUrl('canonical', [
        'absolute' => TRUE,
        'base_url' => self::PUBLIC_BASE_URL,
      ])->toString();
      $items .= '<li><a href="{{ TrackLink "' . $url . '" }}" style="color:#000000;text-decoration:underline">' . Html::escape($node->label()) . '</a>' . Html::escape($this->autoriTestoLabel($node)) . '</li>';
    }

    return '<ul>' . $items . '</ul>';
  }

  /**
   * Righe tabella per il blocco eventi: miniatura B/N a sinistra, poi data e,
   * a capo, il titolo. Layout a tabella (niente flex: non è supportato dagli
   * email client); width 100% così su mobile la riga si contrae da sola.
   * Se l'evento non ha immagine o lo style non è disponibile, si stampa solo
   * la riga testuale.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   */
  private function buildEventiHtml(array $nodes): string {
    $rows = '';
    foreach ($nodes as $node) {
      $url = $node->toUrl('canonical', [
        'absolute' => TRUE,
        'base_url' => self::PUBLIC_BASE_URL,
      ])->toString();
      $tracked = '{{ TrackLink "' . $url . '" }}';
      $dateRow = $this->eventDateLabel($node);
      if ($dateRow !== '') {
        $dateRow = '<div style="font-family:Georgia, \'Times New Roman\', Times, serif; font-size:12px; line-height:18px; color:#5a5a5a;">' . $dateRow . '</div>';
      }
      $title = '<a href="' . $tracked . '" style="color:#000000;text-decoration:underline;font-family:Helvetica, Arial, sans-serif;font-size:15px;line-height:20px;font-weight:600;">' . Html::escape($node->label()) . '</a>';

      $thumb = $this->getEventoImmagine($node);
      $size = self::EVENTO_THUMB_DISPLAY;
      if ($thumb !== NULL) {
        $rows .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 12px 0;"><tr>'
          . '<td width="' . $size . '" valign="top" style="padding:0 ' . max(8, $size / 8) . 'px 0 0;">'
          . '<a href="' . $tracked . '" style="text-decoration:none;border:0;display:block;">'
          . '<img src="' . $thumb['url'] . '" width="' . $size . '" height="' . $size . '" alt="' . $thumb['alt'] . '" '
          . 'style="display:block;width:' . $size . 'px;height:' . $size . 'px;border-radius:4px;border:0;outline:none;">'
          . '</a></td>'
          . '<td valign="middle" style="padding:0;">' . $dateRow . $title . '</td>'
          . '</tr></table>';
      }
      else {
        $rows .= '<div style="margin:0 0 12px 0;">' . $dateRow . $title . '</div>';
      }
    }

    return $rows;
  }

  /**
   * Miniatura B/N dell'evento (image style newsletter_evento, sotto
   * sites/default/files che è esposto pubblicamente dal bypass Authelia).
   * Il derivato viene pre-generato qui così il primo apertore non innesca la
   * generazione on-demand di Drupal.
   *
   * @return array{url: string, alt: string}|NULL
   */
  private function getEventoImmagine(NodeInterface $node): ?array {
    $baseUrl = $this->getPublicBackendUrl();
    if ($baseUrl === '') {
      return NULL;
    }
    if ($node->get('field_immagine')->isEmpty()) {
      return NULL;
    }
    $media = $node->get('field_immagine')->entity;
    if ($media === NULL || !$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
      return NULL;
    }
    $file = $media->get('field_media_image')->entity;
    if ($file === NULL) {
      return NULL;
    }
    $style = $this->entityTypeManager->getStorage('image_style')->load(self::IMAGE_STYLE_NEWSLETTER_EVENTO);
    if ($style === NULL) {
      return NULL;
    }

    $uri = $file->getFileUri();
    $derivative_uri = $style->buildUri($uri);
    // Se il derivato esiste già va riusato: createDerivative() fallisce (e
    // logga "Cached image file ... already exists") quando il file di
    // destinazione è già presente, quindi va chiamato solo se manca.
    if (!file_exists($derivative_uri)) {
      try {
        if (!$style->createDerivative($uri, $derivative_uri)) {
          return NULL;
        }
      }
      catch (\Throwable) {
        return NULL;
      }
    }

    // URL costruito a mano, senza itok: il derivato esiste già (creato ora con
    // createDerivative oppure riusato perché già presente) quindi viene servito
    // staticamente. buildUrl() restituirebbe un URL già assoluto col contesto
    // CLI corrente: va bene in DDEV ma non in prod (drush in crond, host
    // sbagliato), qui è deterministico.
    $relativeTarget = StreamWrapperManager::getTarget($uri);
    $path = '/sites/default/files/styles/' . self::IMAGE_STYLE_NEWSLETTER_EVENTO . '/public/' . $relativeTarget;

    return [
      'url' => $baseUrl . implode('/', array_map('rawurlencode', explode('/', $path))),
      'alt' => '',
    ];
  }

  /**
   * URL base pubblico del backend (admin.ildeposito.org / admin-stage...):
   * serve per gli URL assoluti delle immagini della newsletter. In DDEV cade
   * su DDEV_PRIMARY_URL: il files è servito dallo stesso web container.
   */
  private function getPublicBackendUrl(): string {
    $url = trim((string) Settings::get('ildeposito_utils_public_backend_url', ''));
    if ($url !== '') {
      return rtrim($url, '/');
    }
    $ddev = getenv('DDEV_PRIMARY_URL');

    return $ddev === FALSE ? '' : rtrim($ddev, '/');
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
      $label .= $this->autoriTestoLabel($node);
      $url = $node->toUrl('canonical', [
        'absolute' => TRUE,
        'base_url' => self::PUBLIC_BASE_URL,
      ])->toString();
      $items[] = '- ' . $label . ': ' . $url;
    }

    return implode("\n", $items);
  }

  /**
   * In parentesi, i titoli dei nodi autore collegati (field_autori_testo),
   * es. "(Paolo Pietrangeli)" oppure "(Fausto Amodei, Cantacronache)".
   * Stringa vuota se il campo è vuoto o non presente sul bundle. Il testo
   * non è linkato.
   */
  private function autoriTestoLabel(NodeInterface $node): string {
    if (!$node->hasField('field_autori_testo') || $node->get('field_autori_testo')->isEmpty()) {
      return '';
    }
    $names = [];
    foreach ($node->get('field_autori_testo') as $item) {
      $ref = $item->entity;
      if ($ref !== NULL) {
        $names[] = $ref->label();
      }
    }

    return $names === [] ? '' : ' (' . implode(', ', $names) . ')';
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

    // In prod Listmonk si raggiunge container-to-container sulla rete Docker
    // interna (http://ildeposito_listmonk:9000): dentro Docker il dominio
    // pubblico risolve sull'IP del container Listmonk dove però non c'è nulla
    // in ascolto su 443 (il TLS lo termina Caddy), quindi http è ammesso.
    return $parts !== FALSE
      && in_array($parts['scheme'] ?? '', ['http', 'https'], TRUE)
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