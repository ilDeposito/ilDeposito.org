<?php

declare(strict_types=1);

namespace Drupal\ildeposito_monolog;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\ildeposito_monolog\Logger\Handler\SafeTelegramHandler;
use Monolog\Formatter\LineFormatter;

/**
 * Aggancia un handler Telegram a TUTTI i canali dblog, solo se
 * TELEGRAM_BOT_TOKEN e TELEGRAM_CHAT_ID sono valorizzate nell'ambiente
 * (impostate solo nel .env di produzione, vedi .env.example): altrove
 * (staging, DDEV) resta un no-op, stesso pattern già usato per
 * ildeposito_utils_fbpost_webhook_url in settings.php.
 *
 * Il parametro monolog.channel_handlers non fa merge tra i vari
 * container_yamls (solo override completo, vedi commento in
 * monolog.channel_handlers.remote.yml): per aggiungere l'handler a TUTTI i
 * canali senza duplicare quel file, lo si appende qui via alter(), che gira
 * durante la compilazione del container, DOPO che tutti i container_yamls
 * sono già stati caricati (l'ordine è garantito da
 * DrupalKernel::compileContainer()).
 */
class IldepositoMonologServiceProvider extends ServiceProviderBase {

  private const HANDLER_NAME = 'telegram_critical';

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $token = getenv('TELEGRAM_BOT_TOKEN');
    $chatId = getenv('TELEGRAM_CHAT_ID');

    if (!$token || !$chatId) {
      return;
    }

    // I "%...%" vanno raddoppiati: il container li risolverebbe altrimenti
    // come placeholder di parametri Symfony (es. "%level_name%" cercato tra
    // i parametri del container) invece di lasciarli come segnaposto per
    // LineFormatter, che li sostituisce lui a runtime.
    $container->register('monolog.formatter.' . self::HANDLER_NAME, LineFormatter::class)
      ->setArguments([
        "🚨 ilDeposito PROD — %%level_name%% [%%channel%%]\n%%message%%\n\nURL: %%extra.request_uri%%\nUtente: %%extra.user%% (uid %%extra.uid%%)",
      ])
      ->setShared(FALSE);

    $container->register('monolog.handler.' . self::HANDLER_NAME, SafeTelegramHandler::class)
      ->setArguments([$token, $chatId, 400])
      ->setShared(FALSE);

    if (!$container->hasParameter('monolog.channel_handlers')) {
      return;
    }

    $channelHandlers = $container->getParameter('monolog.channel_handlers');
    foreach ($channelHandlers as $channel => $config) {
      if (!\is_array($config)) {
        continue;
      }

      // Normalizza alla sintassi "nested" del modulo monolog per poter
      // assegnare un formatter al solo handler Telegram, lasciando
      // inalterato il comportamento (formatter di default) degli handler
      // già presenti su quel canale.
      $handlerList = \array_key_exists('handlers', $config) ? $config['handlers'] : \array_map(
        static fn (string $name): array => ['name' => $name],
        $config,
      );

      $alreadyPresent = FALSE;
      foreach ($handlerList as $handler) {
        $name = \is_array($handler) ? ($handler['name'] ?? NULL) : $handler;
        if ($name === self::HANDLER_NAME) {
          $alreadyPresent = TRUE;
          break;
        }
      }
      if ($alreadyPresent) {
        continue;
      }

      $handlerList[] = ['name' => self::HANDLER_NAME, 'formatter' => self::HANDLER_NAME];
      $channelHandlers[$channel] = ['handlers' => $handlerList];
    }
    $container->setParameter('monolog.channel_handlers', $channelHandlers);
  }

}
