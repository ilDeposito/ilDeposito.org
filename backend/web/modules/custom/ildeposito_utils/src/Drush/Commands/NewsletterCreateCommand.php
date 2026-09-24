<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Drush\Commands;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\node\NodeInterface;
use Drush\Commands\AutowireTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Crea una campagna newsletter su Listmonk e la avvia.
 *
 * La campagna usa il template #9 e la lista LISTMONK_NEWSLETTER_LIST_ID (la
 * stessa a cui /api/newsletter iscrive gli utenti; fallback 4 se assente o
 * non valida). Nome e oggetto riflettono la
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
 * - "Articoli consigliati": gli ultimi GHOST_POSTS_LIMIT articoli da Ghost
 *   (Content API v6, www.cosmonauta.dev) con UTM utm_content=cosmonauta.
 *   Ogni articolo è una riga con miniatura a colori a sinistra (feature_image
 *   scaricata e ritagliata quadrata come gli eventi, senza bianco e nero) e
 *   a destra data di pubblicazione in piccolo, titolo linkato e sottotitolo
 *   (custom_excerpt o excerpt). Fase di test: GHOST_TAG vuoto = ultimi 2
 *   articoli qualsiasi; a regime: ultimi 7 giorni con filter tag:[slug].
 * Se una sezione è vuota sparisce; il blocco eventi resta solo se cade un
 * anniversario. I link usano {{ TrackLink }} per il tracking Listmonk e hanno
 * CSS inline (nero, sottolineato). Dentro TrackLink (e come URL nudo con UTM
 * nell'altbody) ogni URL porta utm_source=newsletter, utm_medium=email,
 * utm_campaign=newsletter-AAAA-MM-GG (uno per invio, 1:1 con la campagna) e
 * utm_content a seconda del blocco, letti da Umami. Lo slug viaggia anche in
 * `attribs.utm_campaign` così il template può taggare header/footer con
 * {{ .Campaign.Attribs.utm_campaign }}. Di default la campagna viene anche
 * avviata subito (PUT /api/campaigns/{id}/status con status=running); con
 * --no-launch resta invece in bozza da verificare e inviare da
 * newsletter.ildeposito.org.
 *
 * Le credenziali API (LISTMONK_BASE_URL, LISTMONK_USERNAME, LISTMONK_TOKEN)
 * sono lette dal file .env della root del progetto via settings.php. Se
 * assenti il comando è un no-op, così stage/DDEV non creano campagne.
 */
#[AsCommand(
  name: self::NAME,
  description: 'Crea la campagna newsletter su Listmonk (template 9, lista da LISTMONK_NEWSLETTER_LIST_ID) e la avvia; con --no-launch resta in bozza.',
  aliases: ['iuneventcreate'],
)]
final class NewsletterCreateCommand extends Command {

  use AutowireTrait;

  public const NAME = 'ildeposito:newsletter-create';

  // Template fisso per questa newsletter.
  private const TEMPLATE_ID = 9;

  // Lista di fallback quando LISTMONK_NEWSLETTER_LIST_ID è assente o non è
  // un intero positivo (4 = lista di test).
  private const DEFAULT_LIST_ID = 4;

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

  // Il blocco "Ultimi canti inseriti" (titolo compreso) appare solo se negli
  // ultimi CANTI_ULTIMI_GIORNI giorni sono stati pubblicati canti, altrimenti
  // sparisce del tutto.
  private const SHOW_CANTI_SOLO_ULTIMI_GIORNI = TRUE;

  // UTM letti da Umami: source = canale logico (stabile anche cambiando
  // ESP), medium = tipo di canale, campaign = slug per singolo invio (1:1
  // con la campagna Listmonk), content = blocco della mail.
  private const UTM_SOURCE = 'newsletter';
  private const UTM_MEDIUM = 'email';
  private const UTM_CONTENT_EVENTI = 'eventi-settimana';
  private const UTM_CONTENT_ULTIMI = 'ultimi-canti';
  private const UTM_CONTENT_PIU_VISTI = 'piu-visti';
  private const UTM_CONTENT_GHOST = 'cosmonauta';

  // Blog Ghost (www.cosmonauta.dev, Content API v6, sola lettura): numero di
  // articoli mostrati nel blocco "Articoli consigliati" e finestra temporale
  // (giorni) usata quando GHOST_TAG è valorizzato. Fase di test: GHOST_TAG
  // vuoto = ultimi GHOST_POSTS_LIMIT articoli qualsiasi; a regime:
  // filter=tag:[slug]+published_at:>'...' sugli ultimi GHOST_POSTS_GIORNI gg.
  private const GHOST_POSTS_LIMIT = 2;
  private const GHOST_POSTS_GIORNI = 7;

  // Miniatura degli articoli Ghost: stesso crop quadrato degli eventi
  // (200×200 reso a 100×100) ma a colori, via image style newsletter_ghost.
  // La feature_image remota viene scaricata una sola volta in
  // public://newsletter-ghost/ (chiave = sha1 dell'URL) e riusata dalle
  // campagne successive; oltre GHOST_IMAGE_MAX_BYTES o se non è
  // un'immagine valida la riga resta solo testuale.
  private const IMAGE_STYLE_NEWSLETTER_GHOST = 'newsletter_ghost';
  private const GHOST_THUMB_DISPLAY = 100;
  private const GHOST_IMAGE_MAX_BYTES = 5 * 1024 * 1024;
  private const GHOST_IMAGE_DIR = 'public://newsletter-ghost';

  // Lunghezza massima del sottotitolo (excerpt) degli articoli Ghost.
  private const GHOST_EXCERPT_MAX = 180;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this->addOption('no-launch', NULL, InputOption::VALUE_NONE, 'Crea la campagna ma non la avvia (resta in bozza).');
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
    // Lo slug UTM (newsletter-2026-09-18) deriva dalla stessa data: è 1:1
    // con la campagna Listmonk e fa da join-key con Umami.
    $now = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
    $dateIt = (new \IntlDateFormatter('it_IT', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, date_default_timezone_get()))->format($now->getTimestamp());
    $name = 'Newsletter ' . $dateIt;
    $subject = '[ilDeposito] Newsletter ' . $dateIt;
    $utmCampaign = 'newsletter-' . $now->format('Y-m-d');

    $body = $this->buildBody($utmCampaign);

    $payload = [
      'name' => $name,
      'subject' => $subject,
      'lists' => [$this->getListId()],
      'type' => 'regular',
      'content_type' => 'html',
      'body' => $body['html'],
      'altbody' => $body['text'],
      'from_email' => self::FROM_NAME . ' <' . self::FROM_EMAIL . '>',
      'headers' => [['Reply-To' => self::REPLY_TO_EMAIL]],
      'messenger' => 'email',
      'template_id' => self::TEMPLATE_ID,
      'tags' => ['newsletter'],
      // Il template legge lo slug per singolo invio come
      // {{ .Campaign.Attribs.utm_campaign }} per i link di header/footer.
      'attribs' => ['utm_campaign' => $utmCampaign],
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
    }
    else {
      \Drupal::logger('ildeposito_utils')->info('Campagna Listmonk creata: @name (ID non presente nella risposta).', ['@name' => $name]);
      $output->writeln(sprintf('<info>Campagna creata: %s (ID non presente nella risposta).</info>', $name));
    }

    if ((bool) $input->getOption('no-launch')) {
      return Command::SUCCESS;
    }

    if ($campaignId === NULL) {
      return $this->reportFailure($output, 'ID campagna assente nella risposta Listmonk: avvio non possibile.');
    }

    try {
      // Solo le campagne in bozza (o in pausa) possono passare a running.
      $this->httpClient->request('PUT', $baseUrl . '/api/campaigns/' . $campaignId . '/status', [
        'headers' => [
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
          'Authorization' => 'Basic ' . base64_encode($username . ':' . $token),
        ],
        'json' => ['status' => 'running'],
        'connect_timeout' => 10,
        'timeout' => 30,
      ]);
    }
    catch (\Throwable $e) {
      return $this->reportFailure($output, sprintf('campagna #%d creata ma avvio fallito: %s', $campaignId, $this->getSafeErrorMessage($e)));
    }

    \Drupal::logger('ildeposito_utils')->info('Campagna Listmonk avviata: @name (#@id).', ['@name' => $name, '@id' => $campaignId]);
    $output->writeln(sprintf('<info>Campagna avviata: %s (#%d).</info>', $name, $campaignId));

    return Command::SUCCESS;
  }

  /**
   * Compone intro e blocchi della newsletter in HTML e in testo semplice.
   *
   * I link verso il sito portano gli UTM per Umami (dentro {{ TrackLink }}
   * in HTML, come URL nudo con UTM nell'altbody): Listmonk traccia il click
   * e redirige alla destinazione taggata.
   */
  private function buildBody(string $utmCampaign): array {
    $htmlParts = [];
    $textParts = [];

    $htmlParts[] = '<p>Ecco la newsletter settimanale de ilDeposito.org: gli eventi della storia cantata, i nuovi inserimenti e aggiornamento legati al nostro progetto.</p>';
    $textParts[] = 'Ecco la newsletter settimanale de ilDeposito.org: gli eventi della storia cantata, i nuovi inserimenti e aggiornamento legati al nostro progetto..';

    $eventi = $this->getEventiAnniversarioSettimana();
    if ($eventi !== []) {
      $htmlParts[] = '<h3>Storia cantata: gli eventi della settimana</h3>';
      $htmlParts[] = $this->buildEventiHtml($eventi, $utmCampaign);
      $textParts[] = '';
      $textParts[] = 'Storia cantata: gli eventi della settimana';
      $textParts[] = $this->buildTextList($eventi, $utmCampaign, self::UTM_CONTENT_EVENTI, TRUE);
    }

    $canti = $this->getUltimiCanti();
    if ($canti !== []) {
      $htmlParts[] = '<h3>Ultimi canti inseriti</h3>';
      $htmlParts[] = $this->buildHtmlList($canti, $utmCampaign, self::UTM_CONTENT_ULTIMI);
      $textParts[] = '';
      $textParts[] = 'Ultimi canti inseriti';
      $textParts[] = $this->buildTextList($canti, $utmCampaign, self::UTM_CONTENT_ULTIMI);
    }

    $popolari = $this->getCantiPiuVistiSettimana();
    if ($popolari !== []) {
      $htmlParts[] = '<h3>I canti più visti della settimana</h3>';
      $htmlParts[] = $this->buildHtmlList($popolari, $utmCampaign, self::UTM_CONTENT_PIU_VISTI);
      $textParts[] = '';
      $textParts[] = 'I canti più visti della settimana';
      $textParts[] = $this->buildTextList($popolari, $utmCampaign, self::UTM_CONTENT_PIU_VISTI);
    }

    $ghost = $this->getGhostPosts();
    if ($ghost !== []) {
      $htmlParts[] = '<h3>Articoli consigliati</h3>';
      $htmlParts[] = $this->buildGhostHtmlList($ghost, $utmCampaign);
      $textParts[] = '';
      $textParts[] = 'Articoli consigliati';
      $textParts[] = $this->buildGhostTextList($ghost, $utmCampaign);
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
   * Articoli del blog Ghost (Content API v6, sola lettura, solo pubblicati).
   *
   * Fase di test (GHOST_TAG vuoto): ultimi GHOST_POSTS_LIMIT articoli
   * qualsiasi, ordinati per published_at DESC. A regime (GHOST_TAG
   * valorizzato): articoli degli ultimi GHOST_POSTS_GIORNI giorni con quel
   * tag (filter NQL tag:[slug]+published_at:>'...').
   *
   * Non fallisce mai: config assente, timeout, HTTP non-2xx o JSON non valido
   * → warning in log e lista vuota, così la campagna viene creata comunque
   * senza il blocco "Articoli consigliati".
   *
   * @return array<int, array{title: string, url: string, published_at: string, excerpt: string, feature_image: string}>
   */
  private function getGhostPosts(): array {
    $baseUrl = $this->getGhostApiUrl();
    $key = $this->getGhostKey();
    if ($baseUrl === '' || $key === '') {
      return [];
    }

    $tag = $this->getGhostTag();
    $query = [
      'key' => $key,
      'limit' => (string) self::GHOST_POSTS_LIMIT,
      'order' => 'published_at DESC',
      'fields' => 'title,url,published_at,excerpt,custom_excerpt,feature_image',
    ];
    if ($tag !== '') {
      $since = new \DateTimeImmutable(
        '-' . self::GHOST_POSTS_GIORNI . ' days',
        new \DateTimeZone(date_default_timezone_get()),
      );
      $query['filter'] = 'tag:' . $tag . "+published_at:>'" . $since->format('Y-m-d H:i:s') . "'";
    }

    try {
      $response = $this->httpClient->request('GET', $baseUrl . '/ghost/api/content/posts/', [
        'headers' => [
          'Accept' => 'application/json',
          'Accept-Version' => 'v6.0',
        ],
        'query' => $query,
        'connect_timeout' => 10,
        'timeout' => 15,
      ]);
    }
    catch (\Throwable $e) {
      \Drupal::logger('ildeposito_utils')->warning('Ghost non raggiungibile, blocco "Articoli consigliati" saltato (@class).', ['@class' => $e::class]);
      return [];
    }

    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      \Drupal::logger('ildeposito_utils')->warning('Ghost ha risposto HTTP @code, blocco "Articoli consigliati" saltato.', ['@code' => $response->getStatusCode()]);
      return [];
    }

    try {
      $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      \Drupal::logger('ildeposito_utils')->warning('Risposta Ghost non valida (JSON), blocco "Articoli consigliati" saltato.');
      return [];
    }

    $posts = $payload['posts'] ?? NULL;
    if (!is_array($posts)) {
      \Drupal::logger('ildeposito_utils')->warning('Risposta Ghost senza chiave posts, blocco "Articoli consigliati" saltato.');
      return [];
    }

    $items = [];
    foreach ($posts as $post) {
      if (!is_array($post) || empty($post['title']) || empty($post['url']) || !is_string($post['title']) || !is_string($post['url'])) {
        continue;
      }
      // Sottotitolo: custom_excerpt se l'autore l'ha scritto, altrimenti
      // l'excerpt generato da Ghost; entrambi sono testo semplice.
      $excerpt = $post['custom_excerpt'] ?? '';
      if (!is_string($excerpt) || trim($excerpt) === '') {
        $excerpt = is_string($post['excerpt'] ?? NULL) ? (string) $post['excerpt'] : '';
      }
      $publishedAt = is_string($post['published_at'] ?? NULL) ? (string) $post['published_at'] : '';
      $featureImage = is_string($post['feature_image'] ?? NULL) ? (string) $post['feature_image'] : '';
      $items[] = [
        'title' => $post['title'],
        'url' => $post['url'],
        'published_at' => $publishedAt,
        'excerpt' => $this->truncateExcerpt($excerpt),
        'feature_image' => $featureImage,
      ];
      if (count($items) >= self::GHOST_POSTS_LIMIT) {
        break;
      }
    }

    return $items;
  }

  /**
   * Righe tabella per gli articoli Ghost: miniatura a colori a sinistra,
   * a destra data di pubblicazione in piccolo, titolo linkato e sottotitolo.
   * Stesso layout a tabella degli eventi (niente flex: non è supportato
   * dagli email client); se l'articolo non ha immagine valida si stampa
   * solo la riga testuale.
   *
   * @param array<int, array{title: string, url: string, published_at: string, excerpt: string, feature_image: string}> $posts
   */
  private function buildGhostHtmlList(array $posts, string $utmCampaign): string {
    $rows = '';
    foreach ($posts as $post) {
      $tracked = '{{ TrackLink "' . $this->tagUrl($post['url'], $utmCampaign, self::UTM_CONTENT_GHOST) . '" . }}';
      $dateRow = $this->ghostDateLabel($post['published_at']);
      if ($dateRow !== '') {
        $dateRow = '<div style="font-family:Georgia, \'Times New Roman\', Times, serif; font-size:12px; line-height:18px; color:#5a5a5a;">' . $dateRow . '</div>';
      }
      $title = '<a href="' . $tracked . '" style="color:#000000;text-decoration:underline;font-family:Helvetica, Arial, sans-serif;font-size:15px;line-height:20px;font-weight:600;">' . Html::escape($post['title']) . '</a>';
      $excerptRow = $post['excerpt'] !== ''
        ? '<div style="font-family:Helvetica, Arial, sans-serif;font-size:13px;line-height:18px;color:#333333;">' . Html::escape($post['excerpt']) . '</div>'
        : '';

      $thumb = $this->getGhostImmagine($post);
      $size = self::GHOST_THUMB_DISPLAY;
      if ($thumb !== NULL) {
        $rows .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 12px 0;"><tr>'
          . '<td width="' . $size . '" valign="top" style="padding:0 12px 0 0;">'
          . '<a href="' . $tracked . '" style="text-decoration:none;border:0;display:block;">'
          . '<img src="' . $thumb['url'] . '" width="' . $size . '" height="' . $size . '" alt="' . $thumb['alt'] . '" '
          . 'style="display:block;width:' . $size . 'px;height:' . $size . 'px;border-radius:4px;border:0;outline:none;">'
          . '</a></td>'
          . '<td valign="middle" style="padding:0;">' . $dateRow . $title . $excerptRow . '</td>'
          . '</tr></table>';
      }
      else {
        $rows .= '<div style="margin:0 0 12px 0;">' . $dateRow . $title . $excerptRow . '</div>';
      }
    }

    return $rows;
  }

  /**
   * Stessa lista in testo semplice (altbody): URL nudo con UTM per Umami,
   * con data ed excerpt.
   *
   * @param array<int, array{title: string, url: string, published_at: string, excerpt: string, feature_image: string}> $posts
   */
  private function buildGhostTextList(array $posts, string $utmCampaign): string {
    $items = [];
    foreach ($posts as $post) {
      $label = $post['title'];
      $dateLabel = $this->ghostDateLabel($post['published_at']);
      if ($dateLabel !== '') {
        $label .= ' (' . $dateLabel . ')';
      }
      $items[] = '- ' . $label . ': ' . $this->tagUrl($post['url'], $utmCampaign, self::UTM_CONTENT_GHOST);
      if ($post['excerpt'] !== '') {
        $items[] = '  ' . $post['excerpt'];
      }
    }

    return implode("\n", $items);
  }

  /**
   * Miniatura a colori dell'articolo Ghost: la feature_image remota viene
   * scaricata in public://newsletter-ghost/ (una sola volta per URL) e
   * ritagliata quadrata con lo style newsletter_ghost. Il derivato viene
   * pre-generato qui così il primo apertore non innesca la generazione
   * on-demand di Drupal.
   *
   * @param array{title: string, url: string, published_at: string, excerpt: string, feature_image: string} $post
   *
   * @return array{url: string, alt: string}|NULL
   */
  private function getGhostImmagine(array $post): ?array {
    $baseUrl = $this->getPublicBackendUrl();
    if ($baseUrl === '' || $post['feature_image'] === '') {
      return NULL;
    }
    $sourceUri = $this->downloadGhostImage($post['feature_image']);
    if ($sourceUri === NULL) {
      return NULL;
    }
    $style = $this->entityTypeManager->getStorage('image_style')->load(self::IMAGE_STYLE_NEWSLETTER_GHOST);
    if ($style === NULL) {
      return NULL;
    }

    $derivative_uri = $style->buildUri($sourceUri);
    // Se il derivato esiste già va riusato: createDerivative() fallisce (e
    // logga "Cached image file ... already exists") quando il file di
    // destinazione è già presente, quindi va chiamato solo se manca.
    if (!file_exists($derivative_uri)) {
      try {
        if (!$style->createDerivative($sourceUri, $derivative_uri)) {
          return NULL;
        }
      }
      catch (\Throwable) {
        return NULL;
      }
    }

    // URL costruito a mano, senza itok: il derivato esiste già quindi viene
    // servito staticamente (stesso schema di getEventoImmagine).
    $relativeTarget = StreamWrapperManager::getTarget($sourceUri);
    $path = '/sites/default/files/styles/' . self::IMAGE_STYLE_NEWSLETTER_GHOST . '/public/' . $relativeTarget;

    return [
      'url' => $baseUrl . implode('/', array_map('rawurlencode', explode('/', $path))),
      'alt' => Html::escape($post['title']),
    ];
  }

  /**
   * Scarica la feature_image remota in public://newsletter-ghost/.
   *
   * Accetta solo URL http/https con estensione immagine nota, entro
   * GHOST_IMAGE_MAX_BYTES e con contenuto immagine valido (getimagesize):
   * in ogni altro caso ritorna NULL e la riga resta solo testuale. Il file
   * è indicizzato dallo sha1 dell'URL, così viene scaricato una sola volta.
   *
   * @return string|NULL URI public:// del file locale.
   */
  private function downloadGhostImage(string $url): ?string {
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], TRUE) || empty($parts['host'])) {
      return NULL;
    }
    $extension = strtolower(pathinfo($parts['path'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], TRUE)) {
      return NULL;
    }

    $fileSystem = \Drupal::service('file_system');
    $directory = self::GHOST_IMAGE_DIR;
    $destination = $directory . '/' . sha1($url) . '.' . $extension;
    if (file_exists($destination)) {
      return $destination;
    }

    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['Accept' => 'image/*'],
        'connect_timeout' => 10,
        'timeout' => 20,
        'http_errors' => FALSE,
      ]);
    }
    catch (\Throwable) {
      return NULL;
    }
    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      return NULL;
    }

    $tmp = $fileSystem->tempnam('temporary://', 'ghost_');
    if ($tmp === FALSE) {
      return NULL;
    }
    try {
      if (file_put_contents($tmp, (string) $response->getBody()) === FALSE) {
        return NULL;
      }
      if (filesize($tmp) === FALSE || filesize($tmp) > self::GHOST_IMAGE_MAX_BYTES || getimagesize($tmp) === FALSE) {
        return NULL;
      }
      $fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $moved = $fileSystem->move($tmp, $destination, FileSystemInterface::EXISTS_ERROR);
    }
    finally {
      if (file_exists($tmp)) {
        $fileSystem->unlink($tmp);
      }
    }

    return $moved !== FALSE ? $destination : NULL;
  }

  /**
   * Data di pubblicazione dell'articolo (published_at ISO 8601 di Ghost) in
   * italiano, es. "3 settembre 2026". Stringa vuota se non leggibile.
   */
  private function ghostDateLabel(string $publishedAt): string {
    if ($publishedAt === '') {
      return '';
    }
    try {
      $date = new \DateTimeImmutable($publishedAt);
    }
    catch (\Throwable) {
      return '';
    }

    $formatter = new \IntlDateFormatter('it_IT', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, date_default_timezone_get());

    return (string) $formatter->format($date);
  }

  /**
   * Sottotitolo in testo semplice entro GHOST_EXCERPT_MAX caratteri,
   * tagliato sull'ultimo spazio con ellissi.
   */
  private function truncateExcerpt(string $excerpt): string {
    $excerpt = trim(preg_replace('/\s+/u', ' ', strip_tags($excerpt)) ?? '');
    if (mb_strlen($excerpt) <= self::GHOST_EXCERPT_MAX) {
      return $excerpt;
    }
    $cut = mb_substr($excerpt, 0, self::GHOST_EXCERPT_MAX);
    $space = mb_strrpos($cut, ' ');

    return ($space !== FALSE ? mb_substr($cut, 0, $space) : $cut) . '…';
  }

  /**
   * @param \Drupal\node\NodeInterface[] $nodes
   *   Elenco puntato HTML con link assoluti taggati UTM per Umami e tracciati
   *   via {{ TrackLink }} per Listmonk: CSS inline nero e sottolineato.
   */
  private function buildHtmlList(array $nodes, string $utmCampaign, string $utmContent): string {
    $items = '';
    foreach ($nodes as $node) {
      $url = $node->toUrl('canonical', [
        'absolute' => TRUE,
        'base_url' => self::PUBLIC_BASE_URL,
      ])->toString();
      $tagged = $this->tagUrl($url, $utmCampaign, $utmContent);
      $items .= '<li><a href="{{ TrackLink "' . $tagged . '" . }}" style="color:#000000;text-decoration:underline">' . Html::escape($node->label()) . '</a>' . Html::escape($this->autoriTestoLabel($node)) . '</li>';
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
  private function buildEventiHtml(array $nodes, string $utmCampaign): string {
    $rows = '';
    foreach ($nodes as $node) {
      $url = $node->toUrl('canonical', [
        'absolute' => TRUE,
        'base_url' => self::PUBLIC_BASE_URL,
      ])->toString();
      $tracked = '{{ TrackLink "' . $this->tagUrl($url, $utmCampaign, self::UTM_CONTENT_EVENTI) . '" . }}';
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
   *   Stessa lista in testo semplice (per l'altbody): URL nudo con UTM per
   *   Umami, senza {{ TrackLink }} di Listmonk.
   */
  private function buildTextList(array $nodes, string $utmCampaign, string $utmContent, bool $withDate = FALSE): string {
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
      $items[] = '- ' . $label . ': ' . $this->tagUrl($url, $utmCampaign, $utmContent);
    }

    return implode("\n", $items);
  }

  /**
   * Aggiunge gli UTM per Umami agli URL canonici del sito. Gli UTM già
   * presenti non vengono sovrascritti; query e fragment sono preservati.
   */
  private function tagUrl(string $url, string $utmCampaign, string $utmContent): string {
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
      return $url;
    }
    $query = [];
    parse_str((string) ($parts['query'] ?? ''), $query);
    $query += [
      'utm_source' => self::UTM_SOURCE,
      'utm_medium' => self::UTM_MEDIUM,
      'utm_campaign' => $utmCampaign,
      'utm_content' => $utmContent,
    ];

    $tagged = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
    if (isset($parts['port'])) {
      $tagged .= ':' . $parts['port'];
    }
    $tagged .= $parts['path'] ?? '/';
    $tagged .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    if (isset($parts['fragment'])) {
      $tagged .= '#' . $parts['fragment'];
    }

    return $tagged;
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

  /**
   * ID della lista Listmonk destinataria: LISTMONK_NEWSLETTER_LIST_ID via
   * settings.php (stessa lista delle iscrizioni da /api/newsletter).
   * Fallback a DEFAULT_LIST_ID con warning se assente o non valido, così gli
   * ambienti non configurati mantengono il comportamento precedente.
   */
  private function getListId(): int {
    $listId = (int) trim((string) Settings::get('ildeposito_utils_listmonk_list_id', ''));
    if ($listId > 0) {
      return $listId;
    }
    \Drupal::logger('ildeposito_utils')->warning('LISTMONK_NEWSLETTER_LIST_ID assente o non valido: uso la lista di fallback @id.', ['@id' => self::DEFAULT_LIST_ID]);

    return self::DEFAULT_LIST_ID;
  }

  private function getGhostApiUrl(): string {
    return rtrim(trim((string) Settings::get('ildeposito_utils_ghost_api_url', '')), '/');
  }

  private function getGhostKey(): string {
    return trim((string) Settings::get('ildeposito_utils_ghost_content_key', ''));
  }

  private function getGhostTag(): string {
    return trim((string) Settings::get('ildeposito_utils_ghost_tag', ''));
  }

}