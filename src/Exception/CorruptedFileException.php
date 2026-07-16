<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Exception;

/**
 * Le fichier est illisible ou structurellement invalide.
 *
 * Couvre : fichier absent ou non lisible, en-tête absent ou tronqué, offset
 * pointant hors du fichier, taille absurde, structure incohérente.
 *
 * À distinguer de {@see PreviewNotFoundException} : un fichier **tronqué** est
 * corrompu, pas dépourvu de preview. La distinction est testée — ne la brouille pas.
 */
final class CorruptedFileException extends \RuntimeException implements RawPreviewExtractorException
{
}
