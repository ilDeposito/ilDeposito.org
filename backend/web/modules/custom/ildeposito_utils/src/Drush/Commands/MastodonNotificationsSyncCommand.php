<?php

declare(strict_types=1);
namespace Drupal\ildeposito_utils\Drush\Commands;
use Drupal\ildeposito_utils\Service\MastodonTelegramNotifier;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:self::NAME,description:'Inoltra le nuove notifiche Mastodon nel gruppo Telegram dedicato.',aliases:['iumastodonnotifications'])]
final class MastodonNotificationsSyncCommand extends Command { use AutowireTrait; public const NAME='ildeposito:mastodon-notifications-sync'; public function __construct(private readonly MastodonTelegramNotifier $notifier){parent::__construct();} protected function execute(InputInterface $input,OutputInterface $output):int { if(!$this->notifier->isConfigured()){$output->writeln('<comment>Notifiche Mastodon o gruppo Telegram dedicato non configurati.</comment>');return Command::SUCCESS;} try{$count=$this->notifier->sync();$output->writeln("<info>$count notifiche Mastodon inoltrate.</info>");return Command::SUCCESS;}catch(\Throwable $e){\Drupal::logger('ildeposito_utils')->error('Sync notifiche Mastodon fallito: @m',['@m'=>$e->getMessage()]);$output->writeln('<error>Sync notifiche Mastodon fallito.</error>');return Command::FAILURE;} } }
