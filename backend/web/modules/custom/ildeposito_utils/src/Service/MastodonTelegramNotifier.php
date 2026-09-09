<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Service;

use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;

/** Inoltra nel gruppo tecnico Telegram le interazioni ricevute su Mastodon. */
final class MastodonTelegramNotifier {
  private const STATE_LAST_ID = 'ildeposito_utils.mastodon_notifications_last_id';
  public function __construct(private readonly MastodonClient $mastodon, private readonly ClientInterface $httpClient, private readonly StateInterface $state) {}
  public function isConfigured(): bool { return getenv('ILDEPOSITO_ENV') === 'prod' && $this->mastodon->isConfigured() && getenv('TELEGRAM_BOT_TOKEN') && getenv('TELEGRAM_CHAT_ID'); }
  /** Prima esecuzione silenziosa; le successive inoltrano ogni tipo ricevuto. */
  public function sync(): int {
    if (!$this->isConfigured()) throw new \LogicException('Notifiche Mastodon non configurate.');
    $last=(string)$this->state->get(self::STATE_LAST_ID,'');
    $items=$this->page(['limit'=>80]);
    if($last==='') { if($items!==[]){usort($items,static fn($a,$b)=>strnatcmp((string)($a['id']??''),(string)($b['id']??'')));$this->state->set(self::STATE_LAST_ID,(string)end($items)['id']);} return 0; }
    $all=[];$maxId=NULL;
    do { $page=$this->page(['limit'=>80,'since_id'=>$last]+($maxId===NULL?[]:['max_id'=>$maxId]));$all=array_merge($all,$page);$tail=end($page);$maxId=is_array($tail)&&is_string($tail['id']??NULL)?$tail['id']:NULL; } while(count($page)===80&&$maxId!==NULL);
    $items=$all;
    usort($items,static fn($a,$b)=>strnatcmp((string)($a['id']??''),(string)($b['id']??'')));
    $sent=0; foreach($items as $item){if(!is_array($item)||!is_string($item['id']??NULL))continue;$this->telegram($this->format($item));$this->state->set(self::STATE_LAST_ID,$item['id']);$sent++;} return $sent;
  }
  private function page(array $query): array { $items=json_decode((string)$this->mastodon->request('GET','/api/v1/notifications',['query'=>$query])->getBody(),TRUE,512,JSON_THROW_ON_ERROR);if(!is_array($items))throw new \RuntimeException('Risposta notifiche Mastodon non valida.');return $items; }
  private function telegram(string $text): void { $this->httpClient->request('POST','https://api.telegram.org/bot'.getenv('TELEGRAM_BOT_TOKEN').'/sendMessage',['form_params'=>['chat_id'=>getenv('TELEGRAM_CHAT_ID'),'text'=>$text,'disable_web_page_preview'=>TRUE],'timeout'=>10]); }
  private function format(array $n): string { $type=(string)($n['type']??'interazione');$acct=(string)($n['account']['acct']??'account sconosciuto');$labels=['mention'=>'ti ha menzionato o risposto','favourite'=>'ha messo Mi piace','reblog'=>'ha ricondiviso','follow'=>'ha iniziato a seguirti','follow_request'=>'ha richiesto di seguirti','poll'=>'ha aggiornato un sondaggio','status'=>'ha pubblicato un aggiornamento','update'=>'ha modificato un post'];$line='🦣 Mastodon: @'.$acct.' '.($labels[$type]??('ha generato la notifica “'.$type.'”')).'.';$url=$n['status']['url']??$n['account']['url']??NULL;if(is_string($url)&&$url!=='')$line.="\n".$url;return $line; }
}
