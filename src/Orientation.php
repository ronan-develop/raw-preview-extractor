<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor;

/**
 * How the camera was held, as recorded in the EXIF `Orientation` tag (0x0112).
 *
 * A preview is stored exactly as the sensor captured it. When the photographer
 * held the camera vertically, the JPEG comes out **lying on its side** — the
 * camera records the rotation rather than applying it.
 *
 * This library does not rotate the image: doing so would require GD or Imagick,
 * the very dependencies it exists to avoid. It reports what the file says and
 * lets the caller decide:
 *
 * ```php
 * $preview = $extractor->extract('/photos/IMG_0042.DNG');
 *
 * if (!$preview->orientation->isUpright()) {
 *     // in a browser, free and dependency-less
 *     echo '<img src="thumb.jpg" style="transform: rotate('
 *          . $preview->orientation->degrees() . 'deg)">';
 * }
 * ```
 *
 * The eight EXIF values mix rotations and mirrors. Treating a mirror as a
 * rotation produces a flipped image, so {@see isMirrored()} is worth checking.
 */
enum Orientation: int
{
    /** Upright — nothing to do. The common case. */
    case Normal = 1;

    /** Mirrored along the vertical axis. */
    case FlipHorizontal = 2;

    /** Upside down. */
    case Rotate180 = 3;

    /** Mirrored along the horizontal axis. */
    case FlipVertical = 4;

    /** Mirrored, then rotated 90° clockwise. */
    case Transpose = 5;

    /** Rotated 90° clockwise — a phone held upright. */
    case Rotate90 = 6;

    /** Mirrored, then rotated 270° clockwise. */
    case Transverse = 7;

    /** Rotated 270° clockwise. */
    case Rotate270 = 8;

    /**
     * Builds from a raw EXIF value, tolerating anything out of spec.
     *
     * A tag holding 0, 9 or garbage must not fail an otherwise successful
     * extraction: an unknown orientation is assumed upright.
     *
     * @param int|null $exif the raw tag value, or null when the tag is absent
     */
    public static function fromExif(?int $exif): self
    {
        return null === $exif ? self::Normal : (self::tryFrom($exif) ?? self::Normal);
    }

    /**
     * Clockwise rotation to apply, in degrees: 0, 90, 180 or 270.
     *
     * This is what `imagerotate()` and CSS `transform: rotate()` take — note
     * that `imagerotate()` turns counter-clockwise, so negate it.
     *
     * Mirrored values report the rotation part only; see {@see isMirrored()}.
     */
    public function degrees(): int
    {
        return match ($this) {
            self::Normal, self::FlipHorizontal => 0,
            self::Rotate90, self::Transpose => 90,
            self::Rotate180, self::FlipVertical => 180,
            self::Rotate270, self::Transverse => 270,
        };
    }

    /**
     * Does the image also need mirroring?
     *
     * Four of the eight EXIF values are mirrors. They are rare — most cameras
     * only ever write 1, 3, 6 or 8 — but a caller that ignores them will show
     * some images reversed.
     */
    public function isMirrored(): bool
    {
        return match ($this) {
            self::FlipHorizontal, self::FlipVertical,
            self::Transpose, self::Transverse => true,
            default => false,
        };
    }

    /**
     * Is the image already the right way up?
     *
     * A single check to skip the whole question, which is the common case.
     */
    public function isUpright(): bool
    {
        return self::Normal === $this;
    }

    /**
     * Do width and height swap once the rotation is applied?
     *
     * True for the quarter turns. A caller sizing a container from
     * `$preview->width` before rotating would otherwise get it backwards.
     */
    public function swapsDimensions(): bool
    {
        return 90 === $this->degrees() || 270 === $this->degrees();
    }
}
