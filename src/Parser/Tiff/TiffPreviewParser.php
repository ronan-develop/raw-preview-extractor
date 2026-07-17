<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Parser\Tiff;

use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;
use RonanLenouvel\RawPreviewExtractor\Exception\PreviewNotFoundException;
use RonanLenouvel\RawPreviewExtractor\ExtractedPreview;
use RonanLenouvel\RawPreviewExtractor\Format\Format;
use RonanLenouvel\RawPreviewExtractor\Orientation;
use RonanLenouvel\RawPreviewExtractor\Parser\PreviewParserInterface;

/**
 * Extracts the JPEG preview of RAW files built on TIFF: CR2, NEF, ARW and DNG.
 *
 * A single parser covers the four formats. Rather than hard-coding a
 * manufacturer's layout — which varies from one camera generation to the next —
 * it walks **all** the IFDs, collects every candidate JPEG block and keeps
 * **the largest**.
 *
 * This parser orchestrates: it does not read bytes itself, it delegates to the
 * {@see TiffReader}.
 */
final class TiffPreviewParser implements PreviewParserInterface
{
    /** "Old-style" JPEG compression and JPEG in the TIFF 6.0 sense. */
    private const JPEG_COMPRESSIONS = [6, 7];

    /** Every JPEG starts with this marker (SOI). */
    private const JPEG_MAGIC = "\xFF\xD8";

    /**
     * SOF markers of a genuinely decodable JPEG.
     *
     * `Compression = 6` is not enough to tell a preview from the sensor data:
     * Canon stores the latter as **lossless JPEG** in a CR2, with the same tag.
     * Those blocks are the biggest in the file and would therefore win the
     * comparison by size — only to return 28 MB that no decoder reads.
     *
     * Verified on six cameras: the previews all use SOF0; only a CR2's sensor
     * uses SOF3.
     *
     * @var list<int>
     */
    private const DECODABLE_SOF_MARKERS = [
        0xC0,  // SOF0 — baseline, the case of every observed preview
        0xC1,  // SOF1 — extended sequential
        0xC2,  // SOF2 — progressive
    ];

    /** Maximum recursion depth into the SubIFDs. */
    private const MAX_SUB_IFD_DEPTH = 4;

    public function extract(string $path, Format $format): ExtractedPreview
    {
        $reader = new TiffReader($path);
        $candidates = [];
        $offsets = $reader->readIfdOffsets();

        foreach ($offsets as $offset) {
            $this->collectFromIfd($reader, $offset, $candidates, 0);
        }

        // The orientation of the IFD0 applies to the whole shot, previews
        // included: the camera records how it was held once, not per image.
        $orientation = $this->readOrientation($reader, $offsets[0] ?? null);

        // The largest preview is the most useful: a RAW often carries several,
        // from the 160x120 thumbnail to full resolution.
        usort($candidates, static fn (array $a, array $b): int => $b['length'] <=> $a['length']);

        // A candidate is only kept if it is genuinely decodable: the biggest
        // block of a CR2 is the sensor in lossless JPEG, not a preview.
        foreach ($candidates as $candidate) {
            $preview = $this->tryBuildPreview($reader, $candidate, $format, $orientation);

            if (null !== $preview) {
                return $preview;
            }
        }

        throw new PreviewNotFoundException(
            sprintf('No usable JPEG preview in %s.', basename($path)),
        );
    }

    /**
     * Reads the EXIF orientation of the IFD0.
     *
     * The tag is often absent, and out-of-spec values do occur. Neither is a
     * reason to fail an otherwise successful extraction: an unknown orientation
     * is assumed upright.
     *
     * @throws CorruptedFileException if the IFD cannot be read
     */
    private function readOrientation(TiffReader $reader, ?int $ifd0Offset): Orientation
    {
        if (null === $ifd0Offset) {
            return Orientation::Normal;
        }

        $entries = $this->indexByTag($reader->readIfd($ifd0Offset));

        return Orientation::fromExif(
            ($entries[TiffTag::Orientation->value] ?? null)?->value(),
        );
    }

    /**
     * Collects the candidates of an IFD, then descends into its SubIFDs.
     *
     * @param list<array{offset: int, length: int}> $candidates
     *
     * @throws CorruptedFileException
     */
    private function collectFromIfd(TiffReader $reader, int $offset, array &$candidates, int $depth): void
    {
        if ($depth > self::MAX_SUB_IFD_DEPTH) {
            return;
        }

        $entries = $this->indexByTag($reader->readIfd($offset));

        $candidate = $this->jpegInterchangeCandidate($entries)
            ?? $this->stripCandidate($entries);

        if (null !== $candidate) {
            $candidates[] = $candidate;
        }

        foreach ($this->subIfdOffsets($entries) as $subOffset) {
            $this->collectFromIfd($reader, $subOffset, $candidates, $depth + 1);
        }
    }

    /**
     * The common path: the JPEGInterchangeFormat / …Length pair.
     *
     * @param array<int, IfdEntry> $entries
     *
     * @return array{offset: int, length: int}|null
     */
    private function jpegInterchangeCandidate(array $entries): ?array
    {
        $offset = $entries[TiffTag::JpegInterchangeFormat->value] ?? null;
        $length = $entries[TiffTag::JpegInterchangeFormatLength->value] ?? null;

        if (null === $offset || null === $length) {
            return null;
        }

        return $this->candidate($offset->value(), $length->value());
    }

    /**
     * The other path: StripOffsets / StripByteCounts, if and only if the
     * Compression tag announces JPEG. Without this check, we would mistake the
     * raw sensor data for a preview.
     *
     * @param array<int, IfdEntry> $entries
     *
     * @return array{offset: int, length: int}|null
     */
    private function stripCandidate(array $entries): ?array
    {
        $compression = ($entries[TiffTag::Compression->value] ?? null)?->value();

        if (!in_array($compression, self::JPEG_COMPRESSIONS, true)) {
            return null;
        }

        return $this->candidate(
            ($entries[TiffTag::StripOffsets->value] ?? null)?->value(),
            ($entries[TiffTag::StripByteCounts->value] ?? null)?->value(),
        );
    }

    /**
     * @return array{offset: int, length: int}|null
     */
    private function candidate(?int $offset, ?int $length): ?array
    {
        if (null === $offset || null === $length || $offset < 1 || $length < 1) {
            return null;
        }

        return ['offset' => $offset, 'length' => $length];
    }

    /**
     * @param array<int, IfdEntry> $entries
     *
     * @return list<int>
     */
    private function subIfdOffsets(array $entries): array
    {
        $entry = $entries[TiffTag::SubIfds->value]
            ?? $entries[TiffTag::ExifIfdPointer->value]
            ?? null;

        return $entry?->values ?? [];
    }

    /**
     * @param array{offset: int, length: int} $candidate
     *
     * @throws CorruptedFileException
     */
    private function tryBuildPreview(
        TiffReader $reader,
        array $candidate,
        Format $format,
        Orientation $orientation,
    ): ?ExtractedPreview
    {
        $jpeg = $reader->readBytes($candidate['offset'], $candidate['length']);

        // A tag that lies about its content is common in RAW files: the
        // PowerShot G12 announces Compression = 6 on raw data, in the IFD whose
        // block happens to be the biggest. This is not a corruption of the file
        // — it is one more candidate to rule out.
        if (!str_starts_with($jpeg, self::JPEG_MAGIC)) {
            return null;
        }

        $sof = $this->findSofSegment($jpeg);

        // No SOF, or a SOF that nobody decodes (lossless, arithmetic,
        // differential): this block is not a displayable preview. We move on to
        // the next candidate rather than return unusable bytes.
        if (null === $sof || !in_array($sof['marker'], self::DECODABLE_SOF_MARKERS, true)) {
            return null;
        }

        return new ExtractedPreview($jpeg, $sof['width'], $sof['height'], $format, $orientation);
    }

    /**
     * Locates the SOF segment and extracts its marker and dimensions from it.
     *
     * The SOF is authoritative on the dimensions: the IFD's ImageWidth/ImageLength
     * tags often describe the full-resolution RAW image, not the preview. Its
     * marker also says **how** the image is encoded, hence whether a common
     * decoder will know how to read it.
     *
     * @return array{marker: int, width: int, height: int}|null null if the JPEG
     *                                                          carries no SOF
     */
    private function findSofSegment(string $jpeg): ?array
    {
        $length = strlen($jpeg);
        $position = 2;

        while ($position + 9 < $length) {
            if ("\xFF" !== $jpeg[$position]) {
                ++$position;

                continue;
            }

            $marker = ord($jpeg[$position + 1]);

            // SOF0 to SOF15, except DHT (C4), DNL (C8) and DAC (CC) which share the range.
            if ($marker >= 0xC0 && $marker <= 0xCF && !in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                return [
                    'marker' => $marker,
                    'width' => unpack('n', substr($jpeg, $position + 7, 2))[1],
                    'height' => unpack('n', substr($jpeg, $position + 5, 2))[1],
                ];
            }

            $position += 2 + unpack('n', substr($jpeg, $position + 2, 2))[1];
        }

        return null;
    }

    /**
     * @param list<IfdEntry> $entries
     *
     * @return array<int, IfdEntry>
     */
    private function indexByTag(array $entries): array
    {
        $indexed = [];

        foreach ($entries as $entry) {
            $indexed[$entry->tag] = $entry;
        }

        return $indexed;
    }
}
