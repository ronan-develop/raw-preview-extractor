<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Exception;

/**
 * Le fichier n'est pas un RAW pris en charge par ce package.
 *
 * Deux cas mènent ici : la signature n'est reconnue comme aucun format connu,
 * ou le format est reconnu mais aucun parseur ne lui est associé. Du point de
 * vue de l'appelant, les deux reviennent au même — le fichier ne peut pas être
 * traité.
 *
 * Utilisez {@see \RonanLenouvel\RawPreviewExtractor\RawPreviewExtractorInterface::supports()}
 * pour l'éviter sans passer par un `try`/`catch`.
 */
final class UnsupportedFormatException extends \RuntimeException implements RawPreviewExtractorException
{
}
