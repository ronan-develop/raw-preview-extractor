<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Exception;

/**
 * Le fichier est valide, mais ne contient aucune preview JPEG exploitable.
 *
 * Cas typiques : aucun tag ne désigne de JPEG, ou la taille annoncée est nulle.
 *
 * À distinguer de {@see CorruptedFileException} : un fichier **tronqué** ou dont
 * un offset ment est corrompu, pas dépourvu de preview. Cette frontière est
 * testée — ne la brouille pas.
 */
final class PreviewNotFoundException extends \RuntimeException implements RawPreviewExtractorException
{
}
