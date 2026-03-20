<?php

declare(strict_types=1);

namespace Drupal\ildeposito_utils\Twig;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\Core\Url;
use Drupal\path_alias\AliasManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AliasLangExtension extends AbstractExtension {

  protected AliasManagerInterface $aliasManager;
  protected LanguageManagerInterface $languageManager;
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(
    AliasManagerInterface $alias_manager,
    LanguageManagerInterface $language_manager,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->aliasManager = $alias_manager;
    $this->languageManager = $language_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  public function getFunctions(): array {
    return [
      new TwigFunction('alias_lang', [$this, 'getTranslatedAlias']),
    ];
  }

  public function getTranslatedAlias(?string $alias): Url {

    if ($alias === NULL || trim($alias) === '') {
      return Url::fromRoute('<front>');
    }

    $alias = '/' . ltrim(trim($alias), '/');
    $path = $this->resolveInternalPath($alias);

    // Alias inesistente: restituisce comunque una Url valida.
    if ($path === NULL) {
      return Url::fromUserInput($alias);
    }

    if (preg_match('@^/node/(\d+)$@', $path, $matches)) {
      $nid = (int) $matches[1];
      $node = $this->entityTypeManager
        ->getStorage('node')
        ->load($nid);

      if ($node) {
        $langcode = $this->languageManager
          ->getCurrentLanguage(LanguageInterface::TYPE_URL)
          ->getId();

        if ($node instanceof TranslatableInterface && $node->hasTranslation($langcode)) {
          $node = $node->getTranslation($langcode);
        }

        return $node->toUrl();
      }
    }

    return Url::fromUserInput($path);
  }

  private function resolveInternalPath(string $alias): ?string {
    $language_codes = [
      $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_URL)->getId(),
      LanguageInterface::LANGCODE_NOT_SPECIFIED,
      ...array_keys($this->languageManager->getLanguages()),
    ];

    foreach (array_unique($language_codes) as $langcode) {
      $path = $this->aliasManager->getPathByAlias($alias, $langcode);

      if ($path !== $alias) {
        return $path;
      }
    }

    return NULL;
  }

}