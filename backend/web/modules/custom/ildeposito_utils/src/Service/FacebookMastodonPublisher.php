<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Service;

use Drupal\Core\Database\Connection;
use GuzzleHttp\ClientInterface;

/** Replica selettiva Facebook -> Mastodon, senza permalink Meta. */
final class FacebookMastodonPublisher {
  private const TABLE = 'ildeposito_utils_facebook_mastodon';
  private const LEASE = 600;
  public const RESULT_DONE = 'done';
  public const RESULT_BUSY = 'busy';
  public const RESULT_IGNORED = 'ignored';

  public function __construct(private readonly FacebookPageClient $facebook, private readonly MastodonClient $mastodon, private readonly ClientInterface $httpClient, private readonly Connection $database) {}
  public function isConfigured(): bool { return $this->facebook->isConfigured() && $this->mastodon->isConfigured(); }

  /** Restituisce l'esito editoriale senza caricare né pubblicare risorse. */
  public function preview(string $facebookPostId): array {
    $post = $this->post($facebookPostId);
    $reason = !$this->official($post) ? 'autore non corrispondente alla Pagina' : (!$this->eligible($post) ? 'link Meta oppure tipo media non ammesso' : '');
    $allowed = $reason === '';
    $text = $allowed ? $this->text($post) : '';
    if ($allowed && $text === '') $reason = 'post privo di testo e link esterno';
    return ['allowed' => $allowed && $text !== '', 'reason' => $reason, 'has_image' => $this->image($post) !== NULL, 'parts' => $text === '' ? 0 : count($this->split($text)), 'alt' => $text === '' ? '' : $this->alt($text)];
  }

  public function publish(string $facebookPostId): string {
    if (!$this->claim($facebookPostId)) return $this->status($facebookPostId) === 'sent' ? self::RESULT_DONE : ($this->status($facebookPostId) === 'ignored' ? self::RESULT_IGNORED : self::RESULT_BUSY);
    try {
      $post = $this->post($facebookPostId);
      if (!$this->official($post) || !$this->eligible($post)) { $this->finish($facebookPostId, 'ignored'); return self::RESULT_IGNORED; }
      $text = $this->text($post);
      if ($text === '') { $this->finish($facebookPostId, 'ignored'); return self::RESULT_IGNORED; }
      $mediaId = ($image = $this->image($post)) ? $this->upload($image, $this->alt($text), $facebookPostId) : NULL;
      $reply = NULL;
      foreach ($this->split($text) as $i => $part) $reply = $this->statusPost($part, $mediaId && $i === 0 ? $mediaId : NULL, $reply, "$facebookPostId:$i");
      $this->finish($facebookPostId, 'sent', $reply);
      return self::RESULT_DONE;
    } catch (\Throwable $e) { $this->database->update(self::TABLE)->fields(['status' => 'pending'])->condition('facebook_post_id', $facebookPostId)->execute(); throw $e; }
  }

  private function post(string $id): array { $r=$this->facebook->getAsPage($id,['fields'=>'id,from{id},message,attachments{media_type,url,unshimmed_url,media{image}}']); $p=json_decode((string)$r->getBody(),TRUE,512,JSON_THROW_ON_ERROR); if(!is_array($p)) throw new \RuntimeException('Post Facebook non valido.'); return $p; }
  private function official(array $p): bool { return (string)(($p['from']['id'] ?? '')) === $this->facebook->getPageId(); }
  private function attachments(array $p): array { return is_array($p['attachments']['data'] ?? NULL) ? $p['attachments']['data'] : []; }
  private function eligible(array $p): bool { $photos=0; foreach($this->attachments($p) as $a){$t=$a['media_type']??''; if(in_array($t,['album','video'],TRUE))return FALSE; if($t==='photo')$photos++;} if($photos>1)return FALSE; foreach($this->urls((string)($p['message']??'')) as $u)if($this->meta($u))return FALSE; foreach($this->attachments($p) as $a)if(($a['media_type']??'')!=='photo'&&isset($a['unshimmed_url'])&&$this->meta((string)$a['unshimmed_url']))return FALSE; return TRUE; }
  private function image(array $p): ?string { foreach($this->attachments($p) as $a)if(($a['media_type']??'')==='photo'&&is_string($a['media']['image']['src']??NULL))return $a['media']['image']['src']; return NULL; }
  private function text(array $p): string { $t=trim((string)($p['message']??'')); foreach($this->attachments($p) as $a){$u=$a['unshimmed_url']??NULL;if(($a['media_type']??'')!=='photo'&&is_string($u)&&$u!==''&&!str_contains($t,$u))$t=trim($t."\n\n".$u);} return preg_replace_callback('~https?://[^\s<]+~u', fn(array $m): string => $this->tagUrl($m[0]), $t) ?? $t; }
  private function alt(string $text): string { $a=preg_replace('~https?://\S+|#[\pL\pN_]+~u','',$text)??''; return trim(preg_replace('/\s+/u',' ',$a)??''); }
  private function urls(string $text): array { preg_match_all('~https?://[^\s<]+~u',$text,$m); return $m[0]??[]; }
  private function meta(string $url): bool { $h=strtolower((string)parse_url($url,PHP_URL_HOST)); return $h!==''&&preg_match('~(^|\.)(facebook\.com|fb\.watch|fb\.me|instagram\.com|whatsapp\.com|wa\.me|m\.me)$~',$h)===1; }
  private function split(string $text): array { $limit=$this->mastodon->statusCharacterLimit(); if(mb_strlen($text)<=$limit)return[$text]; $words=preg_split('/\s+/u',$text)?:[];$out=[];$part='';foreach($words as $w){if(mb_strlen(trim("$part $w"))>$limit){$out[]=trim($part);$part=$w;}else $part=trim("$part $w");}if($part!=='')$out[]=$part;return$out; }
  private function tagUrl(string $url): string { $parts=parse_url($url);$host=strtolower((string)($parts['host']??''));if(!in_array($host,['ildeposito.org','www.ildeposito.org'],TRUE))return$url;parse_str((string)($parts['query']??''),$query);$query += ['utm_source'=>'mastodon','utm_medium'=>'social','utm_campaign'=>'facebook_sync','utm_content'=>'facebook_post'];$base=($parts['scheme']??'https').'://'.$host.($parts['path']??'');return$base.'?'.http_build_query($query); }
  private function upload(string $url,string $alt,string $key): string { $image=$this->httpClient->request('GET',$url,['timeout'=>30])->getBody();$r=$this->mastodon->request('POST','/api/v2/media',['headers'=>['Idempotency-Key'=>$key],'multipart'=>[['name'=>'file','contents'=>$image,'filename'=>'facebook-image.jpg'],['name'=>'description','contents'=>$alt]]]);$p=json_decode((string)$r->getBody(),TRUE,512,JSON_THROW_ON_ERROR);if(!is_string($p['id']??NULL))throw new \RuntimeException('Mastodon non ha restituito l’ID media.');return$p['id']; }
  private function statusPost(string $text,?string $media,?string $reply,string $key): string { $f=['status'=>$text,'visibility'=>'public','language'=>'it'];if($media)$f['media_ids[]']=$media;if($reply)$f['in_reply_to_id']=$reply;$r=$this->mastodon->request('POST','/api/v1/statuses',['headers'=>['Idempotency-Key'=>$key],'form_params'=>$f]);$p=json_decode((string)$r->getBody(),TRUE,512,JSON_THROW_ON_ERROR);if(!is_string($p['id']??NULL))throw new \RuntimeException('Mastodon non ha restituito l’ID del post.');return$p['id']; }
  private function claim(string $id): bool { $this->ensure($id);$now=time();return(bool)$this->database->update(self::TABLE)->fields(['status'=>'publishing','processing_started'=>$now])->condition('facebook_post_id',$id)->condition($this->database->condition('OR')->condition('status','pending')->condition($this->database->condition('AND')->condition('status','publishing')->condition('processing_started',$now-self::LEASE,'<=')))->execute(); }
  private function status(string $id): string { return(string)$this->database->select(self::TABLE,'m')->fields('m',['status'])->condition('facebook_post_id',$id)->execute()->fetchField(); }
  private function finish(string $id,string $status,?string $mastodonId=NULL): void { $f=['status'=>$status,'sent'=>time()];if($mastodonId)$f['mastodon_status_id']=$mastodonId;$this->database->update(self::TABLE)->fields($f)->condition('facebook_post_id',$id)->execute(); }
  private function ensure(string $id): void { try{$this->database->insert(self::TABLE)->fields(['facebook_post_id'=>$id,'status'=>'pending','created'=>time()])->execute();}catch(\Exception){} }
}
