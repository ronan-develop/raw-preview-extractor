<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Parser\Tiff;

/**
 * TIFF/EXIF tags useful for locating a JPEG preview.
 *
 * This enum does not claim to cover TIFF 6.0: only the tags the package
 * actually uses appear here. A tag missing from this list is not an error, it
 * is simply ignored while walking.
 */
enum TiffTag: int
{
    /** Width of the image described by the current IFD. */
    case ImageWidth = 0x0100;

    /** Height of the image described by the current IFD. */
    case ImageLength = 0x0101;

    /** Compression scheme: 6 or 7 = JPEG, 1 = uncompressed. */
    case Compression = 0x0103;

    /**
     * How the camera was held — 1 to 8.
     *
     * A preview is stored as the sensor captured it: when the tag is not 1, the
     * JPEG comes out rotated or mirrored. See {@see \RonanLenouvel\RawPreviewExtractor\Orientation}.
     */
    case Orientation = 0x0112;

    /** Camera manufacturer. */
    case Make = 0x010F;

    /** Camera model. */
    case Model = 0x0110;

    /** Offset(s) of the image data strips. */
    case StripOffsets = 0x0111;

    /** Size(s) of the image data strips. */
    case StripByteCounts = 0x0117;

    /** Offsets of the SubIFDs — often where the preview lives. */
    case SubIfds = 0x014A;

    /** Offset of the embedded JPEG. */
    case JpegInterchangeFormat = 0x0201;

    /** Size of the embedded JPEG, in bytes. */
    case JpegInterchangeFormatLength = 0x0202;

    /** Pointer to the EXIF IFD. */
    case ExifIfdPointer = 0x8769;

    /** Present only in DNG files. */
    case DngVersion = 0xC612;
}
