<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor;

use RonanLenouvel\RawPreviewExtractor\Format\Format;

/**
 * Une preview JPEG extraite d'un fichier RAW.
 *
 * ```php
 * $preview = $extractor->extract('/photos/IMG_0042.CR2');
 * file_put_contents('/cache/thumb.jpg', $preview->jpegData);
 * ```
 *
 * Les dimensions proviennent du **JPEG lui-même**, pas des tags du conteneur :
 * ceux-ci décrivent parfois l'image RAW pleine résolution et non la preview.
 */
final readonly class ExtractedPreview
{
    /**
     * @param string $jpegData     JPEG binaire brut, prêt à écrire sur disque ;
     *                             son magic `FFD8` est validé à l'extraction
     * @param int    $width        largeur de la preview, en pixels
     * @param int    $height       hauteur de la preview, en pixels
     * @param Format $sourceFormat format du RAW dont elle provient
     */
    public function __construct(
        public string $jpegData,
        public int $width,
        public int $height,
        public Format $sourceFormat,
    ) {
    }
}
