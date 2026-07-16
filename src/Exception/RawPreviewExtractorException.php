<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Exception;

/**
 * Interface marqueur commune à toutes les exceptions du package.
 *
 * Elle permet à l'appelant de dégrader proprement avec un seul `catch`, sans
 * connaître le détail des cas d'échec :
 *
 * ```php
 * try {
 *     $preview = $extractor->extract($path);
 * } catch (RawPreviewExtractorException) {
 *     // pas de vignette : on continue sans
 * }
 * ```
 *
 * Toute exception levée par ce package l'implémente — c'est un invariant testé.
 */
interface RawPreviewExtractorException extends \Throwable
{
}
