<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Format;

/**
 * RAW formats supported by the package.
 *
 * The value of each case is the format's usual extension, in lowercase. It acts
 * as a stable identifier — notably as the key of the
 * Format → PreviewParserInterface map — and not as a detection criterion:
 * detection relies exclusively on the file's binary signature.
 */
enum Format: string
{
    /** Canon RAW v2 — TIFF container. */
    case CR2 = 'cr2';

    /** Canon RAW v3 — ISO-BMFF container. */
    case CR3 = 'cr3';

    /** Nikon Electronic Format — TIFF container. */
    case NEF = 'nef';

    /** Sony Alpha RAW — TIFF container. */
    case ARW = 'arw';

    /** Adobe Digital Negative — TIFF container. */
    case DNG = 'dng';
}
