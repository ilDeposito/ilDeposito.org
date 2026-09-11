<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Service;

use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;

/** Prepara e invia il riepilogo settimanale delle statistiche Meta. */
final class MetaWeeklyStatsReporter {

  private const STATE_SNAPSHOT = 'ildeposito_utils.meta_weekly_stats.snapshot';

  private const STATE_LAST_REPORT = 'ildeposito_utils.meta_weekly_stats.last_report';

  private const STATE_INSTAGRAM_EVENTS = 'ildeposito_utils.meta_weekly_stats.instagram_events';

  public function __construct(
    private readonly FacebookPageClient $facebookPageClient,
    private readonly FacebookInstagramPublisher $instagramPublisher,
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

  public function isConfigured(): bool {
    return getenv('ILDEPOSITO_ENV') === 'prod'
      && $this->facebookPageClient->isConfigured()
      && getenv('TELEGRAM_NOTIFICHE_BOT_TOKEN')
      && getenv('TELEGRAM_NOTIFICHE_CHAT_ID');
  }

  /**
   * Invia il riepilogo, oppure inizializza il confronto alla prima esecuzione.
   *
   * @return 'baseline'|'sent'|'already_sent'
   */
  public function report(bool $dryRun = FALSE): string {
    if (!$this->isConfigured()) {
      throw new \LogicException('Riepilogo Meta non configurato.');
    }

    $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Rome'));
    $reportKey = $now->format('o-W');
    if (!$dryRun && $this->state->get(self::STATE_LAST_REPORT) === $reportKey) {
      return 'already_sent';
    }

    $current = $this->snapshot();
    $previous = $this->state->get(self::STATE_SNAPSHOT);
    if (!is_array($previous)) {
      if (!$dryRun) {
        $this->state->set(self::STATE_SNAPSHOT, $current);
      }
      return 'baseline';
    }

    $text = $this->format($current, $previous, $now->getTimestamp());
    if (!$dryRun) {
      $this->telegram($text);
      $this->state->set(self::STATE_SNAPSHOT, $current);
      $this->state->set(self::STATE_LAST_REPORT, $reportKey);
    }
    return 'sent';
  }

  /** Rende disponibile l'anteprima senza cambiare lo stato né inviare messaggi. */
  public function preview(): ?string {
    $previous = $this->state->get(self::STATE_SNAPSHOT);
    if (!is_array($previous)) {
      return NULL;
    }
    return $this->format($this->snapshot(), $previous, time());
  }

  /** @return array{collected_at: int, facebook: array<string, int>, instagram: array<string, int>} */
  private function snapshot(): array {
    return [
      'collected_at' => time(),
      'facebook' => $this->facebookSnapshot(),
      'instagram' => $this->instagramSnapshot(),
    ];
  }

  /** @return array<string, int> */
  private function facebookSnapshot(): array {
    $page = $this->graph($this->facebookPageClient->getAsPage($this->facebookPageClient->getPageId(), [
      'fields' => 'followers_count',
    ]));
    $posts = $this->graph($this->facebookPageClient->getFromPage('published_posts', [
      'fields' => 'id,comments.limit(0).summary(true),reactions.type(LIKE).limit(0).summary(true),shares',
      'limit' => 100,
    ]));
    $totals = ['followers' => (int) ($page['followers_count'] ?? 0), 'views' => 0, 'comments' => 0, 'likes' => 0, 'shares' => 0];
    foreach ($posts['data'] ?? [] as $post) {
      if (!is_array($post)) {
        continue;
      }
      $totals['comments'] += $this->summary($post['comments'] ?? []);
      $totals['likes'] += $this->summary($post['reactions'] ?? []);
      $totals['shares'] += (int) (($post['shares']['count'] ?? 0));
      $totals['views'] += $this->metric($this->facebookPageClient, (string) ($post['id'] ?? ''), 'post_impressions', TRUE);
    }
    return $totals;
  }

  /** @return array<string, int> */
  private function instagramSnapshot(): array {
    $accountId = $this->instagramPublisher->getInstagramAccountId();
    $account = $this->graph($this->facebookPageClient->get($accountId, ['fields' => 'followers_count']));
    $media = $this->graph($this->facebookPageClient->get($accountId . '/media', [
      'fields' => 'id,comments_count,like_count',
      'limit' => 100,
    ]));
    $totals = ['followers' => (int) ($account['followers_count'] ?? 0), 'views' => 0, 'comments' => 0, 'likes' => 0, 'saves' => 0, 'shares' => 0];
    foreach ($media['data'] ?? [] as $item) {
      if (!is_array($item)) {
        continue;
      }
      $totals['comments'] += (int) ($item['comments_count'] ?? 0);
      $totals['likes'] += (int) ($item['like_count'] ?? 0);
      $id = (string) ($item['id'] ?? '');
      if ($id === '') {
        continue;
      }
      $totals['views'] += $this->metric($this->facebookPageClient, $id, 'views');
      try {
        $insights = $this->graph($this->facebookPageClient->get($id . '/insights', ['metric' => 'saved,shares']));
        foreach ($insights['data'] ?? [] as $insight) {
          if (!is_array($insight)) {
            continue;
          }
          $name = (string) ($insight['name'] ?? '');
          if (in_array($name, ['saved', 'shares'], TRUE)) {
            $totals[$name === 'saved' ? 'saves' : 'shares'] += $this->insightValue($insight);
          }
        }
      }
      catch (\Throwable) {
        // Alcuni tipi di media non espongono salvataggi o condivisioni.
      }
    }
    return $totals;
  }

  /** @param array<string, mixed> $current @param array<string, mixed> $previous */
  private function format(array $current, array $previous, int $now): string {
    $facebook = $this->delta($current['facebook'] ?? [], $previous['facebook'] ?? []);
    $instagram = $this->delta($current['instagram'] ?? [], $previous['instagram'] ?? []);
    $events = $this->instagramEventsSince((int) ($previous['collected_at'] ?? $now), $now);
    if ($events['comments'] > 0) {
      $instagram['comments'] = $events['comments'];
    }
    $instagram['mentions'] = $events['mentions'];

    return "📊 Meta — Statistiche della settimana\n\n"
      . "🔵 Facebook\n"
      . 'Follower: ' . $this->number($current['facebook']['followers'] ?? 0) . ' (' . $this->signed($facebook['followers'] ?? 0) . ")\n"
      . 'Visualizzazioni dei post: ' . $this->number($facebook['views'] ?? 0) . "\n"
      . 'Commenti: ' . $this->number($facebook['comments'] ?? 0) . "\n"
      . 'Interazioni: ' . $this->number($facebook['likes'] ?? 0) . ' mi piace · ' . $this->number($facebook['shares'] ?? 0) . " condivisioni\n\n"
      . "🟣 Instagram\n"
      . 'Follower: ' . $this->number($current['instagram']['followers'] ?? 0) . ' (' . $this->signed($instagram['followers'] ?? 0) . ")\n"
      . 'Visualizzazioni dei post: ' . $this->number($instagram['views'] ?? 0) . "\n"
      . 'Commenti: ' . $this->number($instagram['comments'] ?? 0) . "\n"
      . 'Menzioni: ' . $this->number($instagram['mentions'] ?? 0) . "\n"
      . 'Interazioni: ' . $this->number($instagram['likes'] ?? 0) . ' mi piace · ' . $this->number($instagram['saves'] ?? 0) . ' salvataggi · ' . $this->number($instagram['shares'] ?? 0) . ' condivisioni';
  }

  /** @param array<string, int> $current @param array<string, int> $previous @return array<string, int> */
  private function delta(array $current, array $previous): array {
    $result = [];
    foreach ($current as $key => $value) {
      if ($key !== 'collected_at') {
        $result[$key] = $value - (int) ($previous[$key] ?? 0);
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
   * Restituisce il contatore di un Insight per contenuto, se Meta lo espone.
   *
   * Immagini e alcuni formati di video non supportano le stesse metriche.
   */
  private function metric(FacebookPageClient $client, string $id, string $metric, bool $asPage = FALSE): int {
    if ($id === '') {
      return 0;
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
      // L'assenza della metrica per un formato non invalida il riepilogo.
    }
    return 0;
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

  private function signed(int $value): string {
    return ($value > 0 ? '+' : '') . $this->number($value);
  }

}
