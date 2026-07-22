<?php

declare(strict_types=1);

namespace Drupal\ildeposito_monolog\Logger\Handler;

use Monolog\Handler\TelegramBotHandler;
use Monolog\Level;

/**
 * TelegramBotHandler con timeout curl brevi e nessuna eccezione propagata.
 *
 * Monolog\Handler\TelegramBotHandler::sendCurl() non imposta alcun timeout
 * (default libcurl: connect illimitato, nessun limite totale) e rilancia
 * l'eccezione se Telegram non risponde. Questo handler viene agganciato a
 * OGNI canale di log: se restasse così, un worker PHP-FPM che sta già
 * gestendo un errore applicativo potrebbe bloccarsi per minuti (o andare in
 * fatal) solo perché api.telegram.org non risponde, trasformando un alert
 * fallito in un'interruzione vera del sito. Le proprietà del genitore sono
 * private (non accessibili da qui), quindi la richiesta viene ricostruita
 * con le sole informazioni necessarie (token, chat, testo semplice).
 */
final class SafeTelegramHandler extends TelegramBotHandler {

  public function __construct(
    private readonly string $apiKeyLocal,
    private readonly string $chatId,
    int $level = 400,
  ) {
    parent::__construct($this->apiKeyLocal, $this->chatId, Level::from($level));
  }

  protected function sendCurl(string $message): void {
    if (\trim($message) === '') {
      return;
    }

    try {
      $ch = \curl_init();
      \curl_setopt($ch, \CURLOPT_URL, 'https://api.telegram.org/bot' . $this->apiKeyLocal . '/SendMessage');
      \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, TRUE);
      \curl_setopt($ch, \CURLOPT_SSL_VERIFYPEER, TRUE);
      \curl_setopt($ch, \CURLOPT_CONNECTTIMEOUT_MS, 2000);
      \curl_setopt($ch, \CURLOPT_TIMEOUT_MS, 3000);
      \curl_setopt($ch, \CURLOPT_POSTFIELDS, \http_build_query([
        'text' => $message,
        'chat_id' => $this->chatId,
      ]));

      $response = \curl_exec($ch);
      if ($response === FALSE) {
        throw new \RuntimeException(\curl_error($ch), \curl_errno($ch));
      }

      $decoded = \json_decode((string) $response, TRUE);
      if (($decoded['ok'] ?? FALSE) === FALSE) {
        throw new \RuntimeException($decoded['description'] ?? 'Errore sconosciuto Telegram API.');
      }
    }
    catch (\Throwable $e) {
      // Non deve MAI propagarsi: vedi doc-block della classe.
      \error_log('[ildeposito_monolog] invio Telegram fallito: ' . $e->getMessage());
    }
  }

}
