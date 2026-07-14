<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class IldepositoUtilsHooks {

  /**
   * Font monospace su testo/accordi per allineare a colpo d'occhio i versi.
   */
  private const MONOSPACE_FIELDS = ['field_canto_testo', 'field_canto_accordi'];

  /**
   * Chiave di state per i destinatari (elenco email separate da virgola) della
   * notifica di login amministratore. Da impostare via drush:
   *
   * @code
   * drush sset ildeposito_utils.notifica_login_destinatari "admin1@example.org,admin2@example.org"
   * @endcode
   */
  private const STATE_NOTIFICA_LOGIN_DESTINATARI = 'ildeposito_utils.notifica_login_destinatari';

  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly StateInterface $state,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
    private readonly RequestStack $requestStack,
  ) {}

  #[Hook('field_widget_single_element_form_alter')]
  public function fieldWidgetSingleElementFormAlter(array &$element, FormStateInterface $form_state, array $context): void {
    if (!\in_array($context['items']->getName(), self::MONOSPACE_FIELDS, TRUE)) {
      return;
    }

    $element['value']['#attributes']['class'][] = 'ildeposito-monospace-field';
    $element['#attached']['library'][] = 'ildeposito_utils/monospace-field';
  }

  #[Hook('entity_presave')]
  public function entityPresave(EntityInterface $entity): void {
    if ($entity instanceof NodeInterface && $entity->bundle() === 'autore') {
      $this->setAutoreTitle($entity);
    }
  }

  /**
   * Calcola il titolo del nodo autore da nome e cognome.
   *
   * Il titolo va tenuto in sync automaticamente perché è il campo usato
   * per il display (link, breadcrumb, SEO) mentre editorialmente i dati
   * anagrafici si inseriscono in field_nome/field_cognome.
   */
  private function setAutoreTitle(NodeInterface $node): void {
    $nome = trim((string) $node->get('field_nome')->value);
    $cognome = trim((string) $node->get('field_cognome')->value);

    $titolo = trim("$nome $cognome");
    if ($titolo === '') {
      return;
    }

    $node->setTitle($titolo);
  }

  /**
   * Implements hook_preprocess_menu_region__middle().
   *
   * Nella voce "Crea" del toolbar Gin, per il ruolo staff mostra solo i link
   * di creazione contenuto (nodi), nascondendo media e termini di tassonomia
   * che compaiono automaticamente in base ai permessi di editing.
   *
   * @see \Drupal\gin\GinNavigation::getNavigationCreateMenuItems()
   */
  #[Hook('preprocess_menu_region__middle')]
  public function preprocessMenuRegionMiddle(array &$variables): void {
    if (($variables['menu_name'] ?? NULL) !== 'create') {
      return;
    }

    if (!\in_array('staff', \Drupal::currentUser()->getRoles(), TRUE)) {
      return;
    }

    if (!isset($variables['items']['create']['below'])) {
      return;
    }

    $variables['items']['create']['below'] = array_filter(
      $variables['items']['create']['below'],
      static fn (array $item): bool => !\in_array($item['class'] ?? NULL, ['media', 'taxonomy'], TRUE),
    );
  }

  /**
   * Etichette leggibili per l'ambiente (ILDEPOSITO_ENV), usate nell'oggetto
   * della notifica di login.
   */
  private const AMBIENTE_LABELS = [
    'prod' => 'produzione',
    'stage' => 'stage',
  ];

  /**
   * Notifica via mail il login di un utente con ruolo amministratore.
   *
   * Solo in stage/prod (ILDEPOSITO_ENV): in locale (DDEV) sarebbe rumore,
   * dato che gli sviluppatori vi accedono come amministratore di continuo.
   *
   * Destinatari configurati tramite lo state
   * ildeposito_utils.notifica_login_destinatari (vedi costante
   * STATE_NOTIFICA_LOGIN_DESTINATARI).
   */
  #[Hook('user_login')]
  public function userLogin(UserInterface $account): void {
    if (!$account->hasRole('administrator')) {
      return;
    }

    $env = (string) $this->requestStack->getCurrentRequest()?->server->get('ILDEPOSITO_ENV', '');
    if (!isset(self::AMBIENTE_LABELS[$env])) {
      return;
    }

    $destinatari = $this->getNotificaLoginDestinatari();
    if ($destinatari === []) {
      return;
    }

    $this->mailManager->mail(
      module: 'ildeposito_utils',
      key: 'notifica_login_admin',
      to: implode(', ', $destinatari),
      langcode: $this->languageManager->getDefaultLanguage()->getId(),
      params: [
        'username' => $account->getAccountName(),
        'ora' => $this->dateFormatter->format($this->time->getRequestTime(), 'custom', 'H:i'),
        'ambiente' => self::AMBIENTE_LABELS[$env],
      ],
    );
  }

  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    if ($key !== 'notifica_login_admin') {
      return;
    }

    $message['subject'] = \sprintf("[ilDeposito] L'utente %s si è loggato in %s", $params['username'], $params['ambiente']);
    $message['body'][] = \sprintf("L'utente %s si è loggato alle ore %s", $params['username'], $params['ora']);
  }

  /**
   * @return array<int, string>
   */
  private function getNotificaLoginDestinatari(): array {
    $raw = (string) $this->state->get(self::STATE_NOTIFICA_LOGIN_DESTINATARI, '');
    if ($raw === '') {
      return [];
    }

    return array_values(array_filter(
      array_map('trim', explode(',', $raw)),
      static fn (string $addr): bool => filter_var($addr, FILTER_VALIDATE_EMAIL) !== FALSE,
    ));
  }

}
