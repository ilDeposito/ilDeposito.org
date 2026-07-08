<?php

declare(strict_types=1);

namespace Drupal\ildeposito_contatti\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ildeposito_contatti\Entity\IldepositoContatto;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Invia la mail di notifica per una submission del form contatti.
 *
 * Disaccoppiato da hook_entity_insert (vedi IldepositoContattiHooks) per non
 * bloccare la risposta JSON:API con il round-trip SMTP. Girato subito dopo la
 * request da ContattiQueueTerminateSubscriber; il cron (impostazione #[cron]
 * sotto) resta come rete di sicurezza per le request che non arrivano a
 * kernel.terminate (crash, drush, ecc.).
 */
#[QueueWorker(
  id: 'ildeposito_contatti_notifica',
  title: new TranslatableMarkup('Notifica contatto ilDeposito'),
  cron: ['time' => 30],
)]
final class NotificaContattoWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly StateInterface $state,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
      $container->get('state'),
    );
  }

  public function processItem($data): void {
    $destinatari = $this->getDestinatari();
    if ($destinatari === []) {
      return;
    }

    $contatto = $this->entityTypeManager->getStorage('ildeposito_contatto')->load($data['contatto_id']);
    if (!$contatto instanceof IldepositoContatto) {
      return;
    }

    $this->mailManager->mail(
      module: 'ildeposito_contatti',
      key: 'notifica_contatto',
      to: implode(', ', $destinatari),
      langcode: $this->languageManager->getDefaultLanguage()->getId(),
      params: [
        'nome' => (string) ($contatto->get('field_nome')->value ?? ''),
        'email' => (string) ($contatto->get('field_email')->value ?? ''),
        'messaggio' => (string) ($contatto->get('field_messaggio')->value ?? ''),
        'titolo' => (string) ($contatto->get('field_titolo')->value ?? ''),
        'link' => (string) ($contatto->get('field_link')->uri ?? ''),
      ],
    );
  }

  /**
   * @return array<int, string>
   */
  private function getDestinatari(): array {
    $raw = (string) $this->state->get('contatti_destinatari', '');
    if ($raw === '') {
      return [];
    }

    return array_values(array_filter(
      array_map('trim', explode(',', $raw)),
      static fn(string $addr): bool => filter_var($addr, FILTER_VALIDATE_EMAIL) !== FALSE,
    ));
  }

}
