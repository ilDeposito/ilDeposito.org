<?php

/**
 * @file
 * Includes the Symfony Runtime bootstrap created by Composer.
 *
 * Il progetto usa un docroot rilocato (web/ sibling di vendor/): index.php
 * fa un require bare 'autoload_runtime.php' risolto relativo a web/, ma
 * drupal/core-composer-scaffold non genera ancora uno stub per questo file
 * (a differenza di autoload.php) — va quindi mantenuto qui a mano, come
 * autoload.php, finché lo scaffold ufficiale non lo copre.
 *
 * @see composer.json
 * @see index.php
 * @see autoload.php
 */

return require __DIR__ . '/../vendor/autoload_runtime.php';
