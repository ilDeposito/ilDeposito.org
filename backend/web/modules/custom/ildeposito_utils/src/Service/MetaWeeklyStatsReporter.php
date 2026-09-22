<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Service;

use Drupal\Core\State\StateInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/** Prepara e invia il riepilogo settimanale delle statistiche social. */
final class MetaWeeklyStatsReporter {

  private const STATE_SNAPSHOT = 'ildeposito_utils.meta_weekly_stats.snapshot';

  private const STATE_LAST_REPORT = 'ildeposito_utils.meta_weekly_stats.last_report';

  private const STATE_INSTAGRAM_EVENTS = 'ildeposito_utils.meta_weekly_stats.instagram_events';

  private const STATE_MASTODON_EVENTS = 'ildeposito_utils.meta_weekly_stats.mastodon_events';

  /**
   * Versione del formato snapshot.
   *
   * v1 = somme cumulative (causava delta negativi); v2 = mappe per post.
   * Uno snapshot v1 viene trattato come baseline da reinizializzare.
   */
  private const SNAPSHOT_VERSION = 2;

  public function __construct(
    private readonly FacebookPageClient $facebookPageClient,
    private readonly FacebookInstagramPublisher $instagramPublisher,
    private readonly MastodonClient $mastodonClient,
    private readonly ClientInterface $httpClient,
    private readonly StateInterface $state,
  ) {}

  /** Registra un evento Instagram, da conteggiare nel prossimo riepilogo. */
  public function recordInstagramEvent(string $field, array $value): void {
    if (!in_array($field, ['comments', 'mentions'], TRUE)) {
      return;
    }
    $events = $this->state->get(self::STATE_INSTAGRAM_EVENTS, []);
    $events = is_array($events) ? $events : [];
    $id = (string) ($value['id'] ?? $value['comment_id'] ?? $value['media_id'] ?? '');
    $key = $field . ':' . ($id !== '' ? $id : hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)));
    $events[$key] = ['field' => $field, 'received' => time()];
    $cutoff = time() - 60 * 86400;
    $events = array_filter($events, static fn (mixed $event): bool => is_array($event) && (int) ($event['received'] ?? 0) >= $cutoff);
    $this->state->set(self::STATE_INSTAGRAM_EVENTS, $events);
  }

  /** Registra una risposta o una menzione Mastodon per il prossimo riepilogo. */
  public function recordMastodonEvent(array $notification, string $accountId): void {
    if (($notification['type'] ?? NULL) !== 'mention' || $accountId === '') {
      return;
    }
    $id = (string) ($notification['id'] ?? '');
    if ($id === '') {
      return;
    }
    $status = is_array($notification['status'] ?? NULL) ? $notification['status'] : [];
    $field = (string) ($status['in_reply_to_account_id'] ?? '') === $accountId ? 'replies' : 'mentions';
    $events = $this->state->get(self::STATE_MASTODON_EVENTS, []);
    $events = is_array($events) ? $events : [];
    $events[$id] = ['field' => $field, 'received' => time()];
    $cutoff = time() - 60 * 86400;
    $events = array_filter($events, static fn (mixed $event): bool => is_array($event) && (int) ($event['received'] ?? 0) >= $cutoff);
    $this->state->set(self::STATE_MASTODON_EVENTS, $events);
  }

  public function isConfigured(): bool {
    return getenv('ILDEPOSITO_ENV') === 'prod'
      && $this->facebookPageClient->isConfigured()
      && getenv('TELEGRAM_NOTIFICHE_BOT_TOKEN')
      && getenv('TELEGRAM_NOTIFICHE_CHAT_ID');
  }

  /**
   * Invia il riepilogo, oppure inizializza il confronto alla prima esecuzione.
   *
   * @param callable(string):void|null $onProgress
   *   Funzione richiamata ad ogni progresso del comando.
   *
   * @return 'baseline'|'sent'|'already_sent'
   */
  public function report(bool $dryRun = FALSE, ?callable $onProgress = NULL): string {
    if (!$this->isConfigured()) {
      throw new \LogicException('Riepilogo Meta non configurato.');
    }

    $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Rome'));
    $reportKey = $now->format('o-W');
    if (!$dryRun && $this->state->get(self::STATE_LAST_REPORT) === $reportKey) {
      return 'already_sent';
    }

    $current = $this->snapshot($onProgress);
    $previous = $this->state->get(self::STATE_SNAPSHOT);
    if (!$this->isComparableSnapshot($previous)) {
      if (!$dryRun) {
        $this->state->set(self::STATE_SNAPSHOT, $current);
      }
      return 'baseline';
    }

    $text = $this->format($current, $previous, $now->getTimestamp());
    if (!$dryRun) {
      $this->progress($onProgress, 'Invio del riepilogo su Telegram…');
      $this->telegram($text);
      $this->state->set(self::STATE_SNAPSHOT, $current);
      $this->state->set(self::STATE_LAST_REPORT, $reportKey);
    }
    return 'sent';
  }

  /**
   * Rende disponibile l'anteprima senza cambiare lo stato né inviare messaggi.
   *
   * @param callable(string):void|null $onProgress
   *   Funzione richiamata ad ogni progresso del comando.
   */
  public function preview(?callable $onProgress = NULL): ?string {
    $previous = $this->state->get(self::STATE_SNAPSHOT);
    if (!$this->isComparableSnapshot($previous)) {
      return NULL;
    }
    return $this->format($this->snapshot($onProgress), $previous, time());
  }

  /**
   * Uno snapshot è confrontabile solo se è in formato v2 (mappe per post).
   *
   * Gli snapshot v1 (somme cumulative) vengono trattati come baseline.
   */
  private function isComparableSnapshot(mixed $snapshot): bool {
    return is_array($snapshot)
      && ($snapshot['version'] ?? NULL) === self::SNAPSHOT_VERSION
      && isset($snapshot['facebook']['posts']) && is_array($snapshot['facebook']['posts'])
      && isset($snapshot['instagram']['media']) && is_array($snapshot['instagram']['media']);
  }

  /** @param callable(string):void|null $onProgress @return array<string, mixed> */
  private function snapshot(?callable $onProgress = NULL): array {
    $this->progress($onProgress, 'Facebook: follower e post della pagina…');
    $facebook = $this->facebookSnapshot($onProgress);

    $this->progress($onProgress, 'Instagram: follower e contenuti…');
    $instagram = $this->instagramSnapshot($onProgress);

    $this->progress($onProgress, 'Mastodon: profilo e interazioni…');
    $mastodon = $this->mastodonSnapshot();

    $this->progress($onProgress, 'Telegram: iscritti al canale…');
    $telegram = $this->telegramChannelSnapshot();

    return [
      'version' => self::SNAPSHOT_VERSION,
      'collected_at' => time(),
      'facebook' => $facebook,
      'instagram' => $instagram,
      'mastodon' => $mastodon,
      'telegram' => $telegram,
    ];
  }

  /** @return array{members: int}|null */
  private function telegramChannelSnapshot(): ?array {
    $token = trim((string) Settings::get('ildeposito_utils_telegram_channel_bot_token', ''));
    $chatId = trim((string) Settings::get('ildeposito_utils_telegram_channel_chat_id', ''));
    if ($token === '' || $chatId === '') {
      return NULL;
    }
    try {
      $response = $this->httpClient->request('GET', 'https://api.telegram.org/bot' . $token . '/getChatMemberCount', [
        'query' => ['chat_id' => $chatId],
        'timeout' => 10,
      ]);
      $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      if (!is_array($payload) || ($payload['ok'] ?? FALSE) !== TRUE || !is_int($payload['result'] ?? NULL)) {
        return NULL;
      }
      return ['members' => $payload['result']];
    }
    catch (\Throwable) {
      // Il canale resta una sorgente opzionale: un errore non blocca il report.
      return NULL;
    }
  }

  /** @return array<string, mixed>|null */
  private function mastodonSnapshot(): ?array {
    if (!$this->mastodonClient->isConfigured()) {
      return NULL;
    }
    try {
      $account = $this->mastodonClient->verifyCredentials();
      $accountId = (string) ($account['id'] ?? '');
      if ($accountId === '') {
        return NULL;
      }
      $statuses = $this->mastodonStatuses($accountId);
      $map = [];
      foreach ($statuses as $status) {
        if (!is_array($status)) {
          continue;
        }
        $id = (string) ($status['id'] ?? '');
        if ($id === '') {
          continue;
        }
        $map[$id] = [
          'created' => $this->toTimestamp($status['created_at'] ?? NULL),
          'favourites' => (int) ($status['favourites_count'] ?? 0),
          'reblogs' => (int) ($status['reblogs_count'] ?? 0),
          'replies' => (int) ($status['replies_count'] ?? 0),
        ];
      }
      return [
        'followers' => (int) ($account['followers_count'] ?? 0),
        'posts_total' => (int) ($account['statuses_count'] ?? 0),
        'statuses' => $map,
      ];
    }
    catch (\Throwable $exception) {
      // Mastodon è una sezione facoltativa del riepilogo: un errore qui non
      // impedisce l'invio delle statistiche degli altri canali, ma va comunque
      // segnalato (il logger error viene inoltrato a Telegram in prod).
      $this->logger()->error('Statistiche settimanali: sezione Mastodon non disponibile, @message', ['@message' => $this->safeErrorMessage($exception)]);
      return NULL;
    }
  }

  /**
   * Legge gli status propri dell'account, paginando finché serve.
   *
   * @return list<array<string, mixed>>
   */
  private function mastodonStatuses(string $accountId): array {
    $all = [];
    $maxId = NULL;
    for ($page = 0; $page < 4; $page++) {
      $query = ['limit' => 80, 'exclude_replies' => 'true', 'exclude_reblogs' => 'true'];
      if ($maxId !== NULL) {
        $query['max_id'] = $maxId;
      }
      $response = $this->mastodonClient->request('GET', '/api/v1/accounts/' . rawurlencode($accountId) . '/statuses', [
        'query' => $query,
      ]);
      $statuses = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      if (!is_array($statuses) || $statuses === []) {
        break;
      }
      foreach ($statuses as $status) {
        if (is_array($status)) {
          $all[] = $status;
        }
      }
      if (count($statuses) < 80) {
        break;
      }
      $tail = end($statuses);
      $maxId = is_array($tail) && isset($tail['id']) ? (string) $tail['id'] : NULL;
      if ($maxId === NULL) {
        break;
      }
    }
    return $all;
  }

  /** @param callable(string):void|null $onProgress @return array<string, mixed> */
  private function facebookSnapshot(?callable $onProgress = NULL): array {
    $page = $this->graph($this->facebookPageClient->getAsPage($this->facebookPageClient->getPageId(), [
      'fields' => 'followers_count',
    ]));
    $postList = $this->graphAllPages(
      fn (): \Psr\Http\Message\ResponseInterface => $this->facebookPageClient->getFromPage('published_posts', [
        // reactions senza type() = totale di tutte le reaction, non solo LIKE.
        'fields' => 'id,created_time,comments.limit(0).summary(true),reactions.limit(0).summary(true),shares',
        'limit' => 100,
      ]),
      500,
    );
    $posts = [];
    $viewsUnavailable = 0;
    $index = 0;
    foreach ($postList as $post) {
      if (!is_array($post)) {
        continue;
      }
      $id = (string) ($post['id'] ?? '');
      if ($id === '') {
        continue;
      }
      $index++;
      $this->progress($onProgress, sprintf('Facebook: post %d di %d…', $index, count($postList)));
      $views = $this->insightMetric($this->facebookPageClient, $id, 'post_impressions', TRUE);
      if ($views === NULL) {
        $viewsUnavailable++;
      }
      $posts[$id] = [
        'created' => $this->toTimestamp($post['created_time'] ?? NULL),
        'comments' => $this->summary($post['comments'] ?? []),
        'likes' => $this->summary($post['reactions'] ?? []),
        'shares' => (int) (($post['shares']['count'] ?? 0)),
        'views' => $views ?? 0,
      ];
    }
    if ($viewsUnavailable > 0) {
      $this->logger()->warning('Statistiche settimanali: insight Facebook post_impressions non disponibile per @n post su @total (permesso read_insights o formato non supportato?).', [
        '@n' => $viewsUnavailable,
        '@total' => count($postList),
      ]);
    }
    return [
      'followers' => (int) ($page['followers_count'] ?? 0),
      'posts' => $posts,
      'views_unavailable' => $viewsUnavailable,
    ];
  }

  /** @param callable(string):void|null $onProgress @return array<string, mixed> */
  private function instagramSnapshot(?callable $onProgress = NULL): array {
    $accountId = $this->instagramPublisher->getInstagramAccountId();
    $account = $this->graph($this->facebookPageClient->get($accountId, ['fields' => 'followers_count']));
    $mediaList = $this->graphAllPages(
      fn (): \Psr\Http\Message\ResponseInterface => $this->facebookPageClient->get($accountId . '/media', [
        'fields' => 'id,timestamp,comments_count,like_count',
        'limit' => 100,
      ]),
      500,
    );
    $media = [];
    $viewsUnavailable = 0;
    $savesUnavailable = 0;
    $index = 0;
    foreach ($mediaList as $item) {
      if (!is_array($item)) {
        continue;
      }
      $id = (string) ($item['id'] ?? '');
      if ($id === '') {
        continue;
      }
      $index++;
      $this->progress($onProgress, sprintf('Instagram: contenuti %d di %d…', $index, count($mediaList)));
      $views = $this->insightMetric($this->facebookPageClient, $id, 'views');
      $saves = NULL;
      $shares = NULL;
      try {
        $insights = $this->graph($this->facebookPageClient->get($id . '/insights', ['metric' => 'saved,shares']));
        foreach ($insights['data'] ?? [] as $insight) {
          if (!is_array($insight)) {
            continue;
          }
          $name = (string) ($insight['name'] ?? '');
          if ($name === 'saved') {
            $saves = $this->insightValue($insight);
          }
          elseif ($name === 'shares') {
            $shares = $this->insightValue($insight);
          }
        }
      }
      catch (\Throwable $exception) {
        $this->logger()->warning('Statistiche settimanali: insight Instagram saved/shares non disponibile per un contenuto (@message).', [
          '@message' => $this->metaErrorMessage($exception),
        ]);
      }
      if ($views === NULL) {
        $viewsUnavailable++;
      }
      if ($saves === NULL || $shares === NULL) {
        $savesUnavailable++;
      }
      $media[$id] = [
        'created' => $this->toTimestamp($item['timestamp'] ?? NULL),
        'comments' => (int) ($item['comments_count'] ?? 0),
        'likes' => (int) ($item['like_count'] ?? 0),
        'views' => $views ?? 0,
        'saves' => $saves ?? 0,
        'shares' => $shares ?? 0,
      ];
    }
    if ($viewsUnavailable > 0) {
      $this->logger()->warning('Statistiche settimanali: insight Instagram views non disponibile per @n contenuti su @total.', [
        '@n' => $viewsUnavailable,
        '@total' => count($mediaList),
      ]);
    }
    return [
      'followers' => (int) ($account['followers_count'] ?? 0),
      'media' => $media,
      'views_unavailable' => $viewsUnavailable,
      'saves_unavailable' => $savesUnavailable,
    ];
  }

  /**
   * Segue la paginazione Graph (`paging.next`) fino a $maxItems elementi.
   *
   * @param callable():\Psr\Http\Message\ResponseInterface $firstPage
   * @return list<array<string, mixed>>
   */
  private function graphAllPages(callable $firstPage, int $maxItems = 500): array {
    $payload = $this->graph($firstPage());
    $items = array_values(array_filter($payload['data'] ?? [], 'is_array'));
    $next = is_array($payload['paging'] ?? NULL) ? ($payload['paging']['next'] ?? NULL) : NULL;
    $pages = 0;
    while (is_string($next) && $next !== '' && count($items) < $maxItems && $pages < 5) {
      $pages++;
      $response = $this->httpClient->request('GET', $next, ['timeout' => 30]);
      $payload = $this->graph($response);
      foreach ($payload['data'] ?? [] as $item) {
        if (is_array($item)) {
          $items[] = $item;
        }
        if (count($items) >= $maxItems) {
          break;
        }
      }
      $next = is_array($payload['paging'] ?? NULL) ? ($payload['paging']['next'] ?? NULL) : NULL;
    }
    return array_slice($items, 0, $maxItems);
  }

  /** @param array<string, mixed> $current @param array<string, mixed> $previous */
  private function format(array $current, array $previous, int $now): string {
    $from = (int) ($previous['collected_at'] ?? $now);

    $facebook = $this->weeklyPostActivity(
      $current['facebook']['posts'] ?? [],
      is_array($previous['facebook'] ?? NULL) ? ($previous['facebook']['posts'] ?? []) : [],
      ['comments', 'likes', 'shares', 'views'],
      $from,
      $now,
    );
    $facebookFollowers = (int) ($current['facebook']['followers'] ?? 0) - (int) ($previous['facebook']['followers'] ?? 0);

    $instagram = $this->weeklyPostActivity(
      $current['instagram']['media'] ?? [],
      is_array($previous['instagram'] ?? NULL) ? ($previous['instagram']['media'] ?? []) : [],
      ['comments', 'likes', 'views', 'saves', 'shares'],
      $from,
      $now,
    );
    $instagramFollowers = (int) ($current['instagram']['followers'] ?? 0) - (int) ($previous['instagram']['followers'] ?? 0);
    // Commenti e like da API per-post (mai negativi); menzioni solo da webhook.
    $instagramMentions = $this->instagramEventsSince($from, $now)['mentions'];

    $mastodonActivity = $this->weeklyPostActivity(
      is_array($current['mastodon'] ?? NULL) ? ($current['mastodon']['statuses'] ?? []) : [],
      is_array($previous['mastodon'] ?? NULL) ? ($previous['mastodon']['statuses'] ?? []) : [],
      ['favourites', 'reblogs', 'replies'],
      $from,
      $now,
    );
    $mastodonFollowers = is_array($current['mastodon'] ?? NULL)
      ? (int) ($current['mastodon']['followers'] ?? 0) - (int) ($previous['mastodon']['followers'] ?? 0)
      : 0;
    $mastodonPosts = $mastodonActivity['_new'] ?? 0;
    $mastodonMentions = $this->mastodonEventsSince($from, $now)['mentions'];

    $telegramMembers = NULL;
    $telegramDelta = 0;
    if (is_array($current['telegram'] ?? NULL)) {
      $telegramMembers = (int) ($current['telegram']['members'] ?? 0);
      $telegramDelta = $telegramMembers - (int) ($previous['telegram']['members'] ?? $telegramMembers);
    }

    $text = "📊 Statistiche settimanali\n\n"
      . "🔵 Facebook\n"
      . 'Follower: ' . $this->number((int) ($current['facebook']['followers'] ?? 0)) . ' (' . $this->signed($facebookFollowers) . ")\n"
      . 'Post pubblicati: ' . $this->number($facebook['_new'] ?? 0) . "\n"
      . 'Visualizzazioni dei post: ' . $this->activity($facebook['views'] ?? 0, (int) ($current['facebook']['views_unavailable'] ?? 0)) . "\n"
      . 'Commenti: ' . $this->number($facebook['comments'] ?? 0) . "\n"
      . 'Interazioni: ' . $this->number($facebook['likes'] ?? 0) . ' reazioni · ' . $this->number($facebook['shares'] ?? 0) . " condivisioni\n\n"
      . "🟣 Instagram\n"
      . 'Follower: ' . $this->number((int) ($current['instagram']['followers'] ?? 0)) . ' (' . $this->signed($instagramFollowers) . ")\n"
      . 'Contenuti pubblicati: ' . $this->number($instagram['_new'] ?? 0) . "\n"
      . 'Visualizzazioni dei post: ' . $this->activity($instagram['views'] ?? 0, (int) ($current['instagram']['views_unavailable'] ?? 0)) . "\n"
      . 'Commenti: ' . $this->number($instagram['comments'] ?? 0) . "\n"
      . 'Menzioni: ' . $this->number($instagramMentions) . "\n"
      . 'Interazioni: ' . $this->number($instagram['likes'] ?? 0) . ' mi piace · ' . $this->number($instagram['saves'] ?? 0) . ' salvataggi · ' . $this->number($instagram['shares'] ?? 0) . ' condivisioni';

    if (is_array($current['mastodon'] ?? NULL)) {
      $text .= "\n\n🟢 Mastodon\n"
        . 'Follower: ' . $this->number((int) ($current['mastodon']['followers'] ?? 0)) . ' (' . $this->signed($mastodonFollowers) . ")\n"
        . 'Post pubblicati: ' . $this->number($mastodonPosts) . "\n"
        . 'Interazioni: ' . $this->number($mastodonActivity['favourites'] ?? 0) . ' preferiti · ' . $this->number($mastodonActivity['reblogs'] ?? 0) . ' boost · ' . $this->number($mastodonActivity['replies'] ?? 0) . " risposte\n"
        . 'Menzioni: ' . $this->number($mastodonMentions);
    }
    if ($telegramMembers !== NULL) {
      $text .= "\n\n🔵 Telegram\n"
        . 'Iscritti al canale: ' . $this->number($telegramMembers) . ' (' . $this->signed($telegramDelta) . ')';
    }
    return $text;
  }

  /**
   * Attività settimanale su mappe per-post.
   *
   * Post nuovi nella finestra = intero valore; post già visti = solo
   * l'incremento non negativo. I post spariti vengono ignorati (non sottratti).
   *
   * @param array<string, array<string, int>> $currMap
   * @param array<string, array<string, int>> $prevMap
   * @param list<string> $keys
   * @return array<string, int> Attività per chiave + '_new' (post nuovi).
   */
  private function weeklyPostActivity(array $currMap, array $prevMap, array $keys, int $from, int $now): array {
    $result = ['_new' => 0];
    foreach ($keys as $key) {
      $result[$key] = 0;
    }
    foreach ($currMap as $id => $curr) {
      if (!is_array($curr)) {
        continue;
      }
      $created = (int) ($curr['created'] ?? 0);
      $prev = $prevMap[$id] ?? NULL;
      if (!is_array($prev)) {
        // Post mai visto: conta solo se creato nella finestra (altrimenti è
        // un post vecchio entrato in finestra di paginazione: ignorato).
        if ($created > $from && $created <= $now) {
          $result['_new']++;
          foreach ($keys as $key) {
            $result[$key] += max(0, (int) ($curr[$key] ?? 0));
          }
        }
        continue;
      }
      foreach ($keys as $key) {
        $result[$key] += max(0, (int) ($curr[$key] ?? 0) - (int) ($prev[$key] ?? 0));
      }
      if ($created > $from && $created <= $now) {
        $result['_new']++;
      }
    }
    return $result;
  }

  /** @return array{comments: int, mentions: int} */
  private function instagramEventsSince(int $from, int $until): array {
    $result = ['comments' => 0, 'mentions' => 0];
    foreach ($this->state->get(self::STATE_INSTAGRAM_EVENTS, []) as $event) {
      if (!is_array($event) || (int) ($event['received'] ?? 0) < $from || (int) ($event['received'] ?? 0) >= $until) {
        continue;
      }
      $field = $event['field'] ?? '';
      if (isset($result[$field])) {
        $result[$field]++;
      }
    }
    return $result;
  }

  /** @return array{replies: int, mentions: int} */
  private function mastodonEventsSince(int $from, int $until): array {
    $result = ['replies' => 0, 'mentions' => 0];
    foreach ($this->state->get(self::STATE_MASTODON_EVENTS, []) as $event) {
      if (!is_array($event) || (int) ($event['received'] ?? 0) < $from || (int) ($event['received'] ?? 0) >= $until) {
        continue;
      }
      $field = $event['field'] ?? '';
      if (isset($result[$field])) {
        $result[$field]++;
      }
    }
    return $result;
  }

  /** @return array<string, mixed> */
  private function graph(\Psr\Http\Message\ResponseInterface $response): array {
    $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
      throw new \RuntimeException('Meta ha restituito una risposta non valida per il riepilogo settimanale.');
    }
    return $payload;
  }

  private function summary(mixed $value): int {
    return is_array($value) ? (int) (($value['summary']['total_count'] ?? 0)) : 0;
  }

  /** @param array<string, mixed> $insight */
  private function insightValue(array $insight): int {
    $values = $insight['values'] ?? [];
    $last = is_array($values) ? end($values) : FALSE;
    return is_array($last) ? (int) ($last['value'] ?? 0) : 0;
  }

  /**
   * Insight per contenuto: valore o NULL se Meta non lo espone.
   *
   * A differenza del vecchio metric(), il fallimento è distinguibile dallo
   * zero reale e viene conteggiato dal chiamante come "non disponibile".
   */
  private function insightMetric(FacebookPageClient $client, string $id, string $metric, bool $asPage = FALSE): ?int {
    if ($id === '') {
      return NULL;
    }
    try {
      $response = $asPage
        ? $client->getAsPage($id . '/insights', ['metric' => $metric])
        : $client->get($id . '/insights', ['metric' => $metric]);
      $payload = $this->graph($response);
      foreach ($payload['data'] ?? [] as $insight) {
        if (is_array($insight) && ($insight['name'] ?? NULL) === $metric) {
          return $this->insightValue($insight);
        }
      }
    }
    catch (\Throwable) {
      // Conteggio "non disponibile" a cura del chiamante, con warning aggregato.
      return NULL;
    }
    return NULL;
  }

  private function telegram(string $text): void {
    $this->httpClient->request('POST', 'https://api.telegram.org/bot' . getenv('TELEGRAM_NOTIFICHE_BOT_TOKEN') . '/sendMessage', [
      'form_params' => [
        'chat_id' => getenv('TELEGRAM_NOTIFICHE_CHAT_ID'),
        'text' => $text,
        'disable_web_page_preview' => TRUE,
      ],
      'timeout' => 10,
    ]);
  }

  private function number(int $value): string {
    return number_format($value, 0, ',', '.');
  }

  /**
   * Attività con gestione "non disponibile": mai negativa, mai zero falso.
   */
  private function activity(int $value, int $unavailable): string {
    if ($value === 0 && $unavailable > 0) {
      return 'n.d.';
    }
    return $this->number(max(0, $value));
  }

  private function signed(int $value): string {
    return ($value > 0 ? '+' : '') . $this->number($value);
  }

  private function toTimestamp(mixed $value): int {
    if (is_int($value) && $value > 0) {
      return $value;
    }
    if (is_string($value) && $value !== '') {
      $parsed = strtotime($value);
      if ($parsed !== FALSE) {
        return $parsed;
      }
    }
    return 0;
  }

  /** @return \Psr\Log\LoggerInterface */
  private function logger(): \Psr\Log\LoggerInterface {
    return \Drupal::logger('ildeposito_utils');
  }

  /** @param callable(string):void|null $onProgress */
  private function progress(?callable $onProgress, string $message): void {
    if ($onProgress !== NULL) {
      $onProgress($message);
    }
  }

  /**
   * Produce un messaggio diagnostico privo di URL o parametri segreti.
   */
  private function safeErrorMessage(\Throwable $exception): string {
    if ($exception instanceof RequestException && $exception->getResponse() !== NULL) {
      $response = $exception->getResponse();
      try {
        $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException) {
        $payload = [];
      }
      $error = is_array($payload) ? ($payload['error'] ?? NULL) : NULL;
      if (is_string($error)) {
        return sprintf('Mastodon ha risposto HTTP %d: %s', $response->getStatusCode(), $error);
      }
      return sprintf('Mastodon ha risposto HTTP %d.', $response->getStatusCode());
    }
    return sprintf('errore interno durante la richiesta (%s)', $exception::class);
  }

  /**
   * Diagnostica Meta priva di URL o parametri segreti (per i warning insight).
   */
  private function metaErrorMessage(\Throwable $exception): string {
    if ($exception instanceof RequestException && $exception->getResponse() !== NULL) {
      $response = $exception->getResponse();
      try {
        $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException) {
        $payload = [];
      }
      $error = is_array($payload) ? ($payload['error'] ?? NULL) : NULL;
      if (is_array($error) && is_string($error['message'] ?? NULL)) {
        return sprintf('HTTP %d: %s', $response->getStatusCode(), $error['message']);
      }
      return sprintf('HTTP %d.', $response->getStatusCode());
    }
    return sprintf('errore interno (%s)', $exception::class);
  }

}
