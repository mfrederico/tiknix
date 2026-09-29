<?php
/**
 * ConceptException — a concept's manifest, files or wiring are wrong.
 *
 * Its own type so a caller can tell "this concept is broken" from any other runtime fault,
 * and so the CLI can list a broken concept beside the working ones instead of dying on it.
 * Every message names the concept and the manifest entry at fault.
 */

namespace app;

class ConceptException extends \RuntimeException {
}
