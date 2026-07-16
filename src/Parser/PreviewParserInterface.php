<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Parser;

use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;
use RonanLenouvel\RawPreviewExtractor\Exception\PreviewNotFoundException;
use RonanLenouvel\RawPreviewExtractor\ExtractedPreview;
use RonanLenouvel\RawPreviewExtractor\Format\Format;

/**
 * Extrait la preview JPEG d'un fichier RAW d'un conteneur donné.
 *
 * Une implémentation par famille de conteneur : TIFF pour CR2/NEF/ARW/DNG,
 * ISO-BMFF pour CR3. Toutes sont interchangeables derrière ce contrat — c'est
 * ce qui permet à la façade de résoudre par une simple map `Format → parser`,
 * et d'accueillir un nouveau format sans être modifiée.
 */
interface PreviewParserInterface
{
    /**
     * @param string $path   chemin du fichier RAW
     * @param Format $format format déjà identifié par le détecteur
     *
     * @throws PreviewNotFoundException si le fichier est valide mais sans preview
     * @throws CorruptedFileException   si le fichier est illisible ou incohérent
     */
    public function extract(string $path, Format $format): ExtractedPreview;
}
