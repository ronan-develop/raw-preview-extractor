<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor;

/**
 * Shooting metadata read from a RAW file's EXIF, alongside its preview.
 *
 * ```php
 * $preview = $extractor->extract('/photos/IMG_0042.CR2');
 * $meta = $preview->metadata;
 * echo $meta?->fNumber;        // 2.8
 * echo $meta?->exposureTime;   // "1/250"
 * ```
 *
 * Every field is nullable: a tag may be absent, and a partial read is not a
 * failure — an old body simply says less than a recent one. Values are exposed
 * as the file encodes them (no rounding, no locale): the shutter speed keeps its
 * fraction, the aperture stays a plain number.
 */
final readonly class RawMetadata
{
    /**
     * @param string|null $dateTimeOriginal capture date, raw EXIF form
     *                                      "YYYY:MM:DD HH:MM:SS", or null
     * @param float|null  $fNumber          aperture as an f-number (2.8), or null
     * @param string|null $exposureTime     shutter speed kept as a fraction
     *                                      ("1/250") or decimal seconds ("0.5"), or null
     * @param int|null    $iso              ISO sensitivity, or null
     * @param float|null  $focalLength      focal length in millimetres, or null
     * @param string|null $lensModel        lens model, or null
     * @param string|null $cameraMake       camera manufacturer, or null
     * @param string|null $cameraModel      camera model, or null
     */
    public function __construct(
        public ?string $dateTimeOriginal = null,
        public ?float $fNumber = null,
        public ?string $exposureTime = null,
        public ?int $iso = null,
        public ?float $focalLength = null,
        public ?string $lensModel = null,
        public ?string $cameraMake = null,
        public ?string $cameraModel = null,
    ) {
    }

    /**
     * True when every field is null — nothing usable was found.
     *
     * Lets a caller skip an empty metadata object rather than test each field.
     */
    public function isEmpty(): bool
    {
        return null === $this->dateTimeOriginal
            && null === $this->fNumber
            && null === $this->exposureTime
            && null === $this->iso
            && null === $this->focalLength
            && null === $this->lensModel
            && null === $this->cameraMake
            && null === $this->cameraModel;
    }
}
