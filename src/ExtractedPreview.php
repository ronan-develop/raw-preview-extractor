<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor;

use RonanLenouvel\RawPreviewExtractor\Format\Format;

/**
 * A JPEG preview extracted from a RAW file.
 *
 * ```php
 * $preview = $extractor->extract('/photos/IMG_0042.CR2');
 * file_put_contents('/cache/thumb.jpg', $preview->jpegData);
 * ```
 *
 * Dimensions come from the **JPEG itself**, not from container tags: those often
 * describe the full-resolution RAW image rather than the preview.
 */
final readonly class ExtractedPreview
{
    /**
     * @param string $jpegData     raw JPEG bytes, ready to write to disk; the
     *                             `FFD8` magic is validated during extraction
     * @param int    $width        preview width in pixels
     * @param int    $height       preview height in pixels
     * @param Format $sourceFormat format of the RAW file it came from
     */
    public function __construct(
        public string $jpegData,
        public int $width,
        public int $height,
        public Format $sourceFormat,
    ) {
    }
}
