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
 *
 * A preview is stored exactly as the sensor captured it, so one shot in portrait
 * comes out lying on its side. {@see $orientation} says how to put it upright —
 * rotating it here would require GD, which this package exists to avoid.
 */
final readonly class ExtractedPreview
{
    /**
     * @param string      $jpegData     raw JPEG bytes, ready to write to disk;
     *                                  the `FFD8` magic is validated during
     *                                  extraction
     * @param int         $width        preview width in pixels, as encoded
     * @param int         $height       preview height in pixels, as encoded
     * @param Format      $sourceFormat format of the RAW file it came from
     * @param Orientation $orientation  how the camera was held; defaults to
     *                                  {@see Orientation::Normal} when the file
     *                                  says nothing
     */
    public function __construct(
        public string $jpegData,
        public int $width,
        public int $height,
        public Format $sourceFormat,
        public Orientation $orientation = Orientation::Normal,
    ) {
    }
}
