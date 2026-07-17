<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Parser\Cr3;

use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;
use RonanLenouvel\RawPreviewExtractor\Exception\PreviewNotFoundException;
use RonanLenouvel\RawPreviewExtractor\ExtractedPreview;
use RonanLenouvel\RawPreviewExtractor\Format\Format;
use RonanLenouvel\RawPreviewExtractor\Orientation;
use RonanLenouvel\RawPreviewExtractor\Parser\PreviewParserInterface;
use RonanLenouvel\RawPreviewExtractor\Parser\Tiff\TiffReader;
use RonanLenouvel\RawPreviewExtractor\Parser\Tiff\TiffTag;

/**
 * Extracts the JPEG preview of a CR3 (Canon RAW v3, ISO-BMFF container).
 *
 * The preview lives in the `PRVW` box, under a dedicated `uuid` box **at the
 * root** of the file. `THMB` carries a much smaller thumbnail, under the Canon
 * UUID, and serves as a fallback.
 *
 * **CR3 has no public specification**: its structure comes from community
 * reverse engineering and may vary depending on the model. The code is
 * therefore written to be tolerant — it locates the JPEG by its magic rather
 * than relying on fixed offsets.
 *
 * This parser orchestrates: it does not read bytes itself, it delegates to
 * {@see IsoBmffBoxReader}.
 */
final class Cr3PreviewParser implements PreviewParserInterface
{
    /**
     * Preview locations, in order of preference.
     *
     * `PRVW` and `THMB` live **neither under the same UUID, nor at the same
     * level** — structure verified on Canon EOS R and EOS RP:
     *
     * ```
     * ftyp
     * moov
     *   └── uuid 85c0b687…   → CMT1, CMT2, THMB   (thumbnail ~15 KB)
     * uuid eaf42b5e…          → PRVW              (preview ~250 KB)
     * mdat
     * ```
     *
     * Looking for both under the Canon UUID only finds the thumbnail.
     *
     * @var list<array{uuid: string, box: string}>
     */
    private const PREVIEW_LOCATIONS = [
        // The real preview, in its own uuid box at the root.
        ['uuid' => 'eaf42b5e1c984b88b9fbb7dc406e4d16', 'box' => 'PRVW'],
        // Fallback: the thumbnail of the Canon UUID, under moov.
        ['uuid' => '85c0b687820f11e08111f4ce462b6a48', 'box' => 'THMB'],
    ];

    /** Start marker of every JPEG (SOI). */
    private const JPEG_MAGIC = "\xFF\xD8";

    /** Length of the UUID that follows the type of a `uuid` box. */
    private const UUID_LENGTH = 16;

    /**
     * Box holding the EXIF of the IFD0 — the orientation among them.
     *
     * A CR3 has no TIFF of its own, but `CMT1` **is** one, header included.
     */
    private const EXIF_BOX = 'CMT1';

    public function extract(string $path, Format $format): ExtractedPreview
    {
        $reader = new IsoBmffBoxReader($path);

        foreach (self::PREVIEW_LOCATIONS as $location) {
            $container = $reader->findUuid((string) hex2bin($location['uuid']));

            if (null === $container) {
                continue;
            }

            $jpeg = $this->jpegFromBox($reader, $container, $location['box']);

            if (null !== $jpeg) {
                [$width, $height] = $this->readJpegDimensions($jpeg);

                return new ExtractedPreview(
                    $jpeg,
                    $width,
                    $height,
                    $format,
                    $this->readOrientation($reader, $path),
                );
            }
        }

        throw new PreviewNotFoundException(sprintf(
            'No JPEG preview in %s: neither PRVW nor THMB usable.',
            basename($path),
        ));
    }

    /**
     * Reads the orientation from the `CMT1` box.
     *
     * A CR3 carries no TIFF of its own, but `CMT1` is one — header and all. The
     * reader works on that window of the host file: no temporary file, no copy
     * in memory.
     *
     * The box is absent on some models, and nothing guarantees its content.
     * Neither is a reason to fail an extraction that otherwise succeeded, so an
     * unreadable orientation is assumed upright.
     *
     * @throws CorruptedFileException if the box structure is invalid
     */
    private function readOrientation(IsoBmffBoxReader $reader, string $path): Orientation
    {
        $canon = $reader->findUuid((string) hex2bin(self::PREVIEW_LOCATIONS[1]['uuid']));

        if (null === $canon) {
            return Orientation::Normal;
        }

        $box = $this->findWithin($reader, $canon, self::EXIF_BOX);

        if (null === $box) {
            return Orientation::Normal;
        }

        try {
            $tiff = TiffReader::fromRange($path, $box->payloadOffset, $box->payloadLength);

            foreach ($tiff->readIfd($tiff->readIfdOffsets()[0] ?? 8) as $entry) {
                if (TiffTag::Orientation->value === $entry->tag) {
                    return Orientation::fromExif($entry->value());
                }
            }
        } catch (CorruptedFileException) {
            // CMT1 exists but holds no readable TIFF. That is metadata trouble,
            // not preview trouble: the JPEG we found is still fine.
            return Orientation::Normal;
        }

        return Orientation::Normal;
    }

    /**
     * Extracts the JPEG from a preview box, if it is there.
     *
     * @throws CorruptedFileException if the structure is invalid
     */
    private function jpegFromBox(IsoBmffBoxReader $reader, Box $container, string $type): ?string
    {
        // Some uuid containers start with proprietary bytes: the normal walk
        // then fails, and we fall back on a search by type.
        $box = $this->findWithin($reader, $container, $type)
            ?? $this->findByScanning($reader, $container, $type);

        if (null === $box) {
            return null;
        }

        $payload = $reader->readPayload($box);

        // PRVW precedes its JPEG with a proprietary header whose size varies
        // between models and is not documented. Looking for the magic is more
        // robust than hard-coding an offset — and the magic has to be validated
        // anyway.
        $start = strpos($payload, self::JPEG_MAGIC);

        return false === $start ? null : substr($payload, $start);
    }

    /**
     * Looks for a box among the direct children of a uuid container.
     *
     * We do not search the whole file: a `PRVW` box elsewhere is not the CR3's
     * preview, and relying on it would amount to trusting any box carrying the
     * right name.
     *
     * @throws CorruptedFileException if the structure is invalid
     */
    private function findWithin(IsoBmffBoxReader $reader, Box $container, string $type): ?Box
    {
        foreach ($reader->childBoxes($container) as $box) {
            if ($box->type === $type) {
                return $box;
            }
        }

        return null;
    }

    /**
     * Looks for a box in the raw content of a container, without assuming that
     * the latter starts with a box.
     *
     * The `uuid` box carrying `PRVW` inserts **8 proprietary bytes** between the
     * UUID and the first box — verified on EOS R and EOS RP. Its size is
     * documented nowhere, and nothing guarantees it is the same everywhere. We
     * therefore locate the sought type within the content, rather than
     * hard-coding an offset.
     *
     * @throws CorruptedFileException if the structure is invalid
     */
    private function findByScanning(IsoBmffBoxReader $reader, Box $container, string $type): ?Box
    {
        $payload = $reader->readPayload($container);
        $position = strpos($payload, $type, self::UUID_LENGTH);

        // The type is preceded by the 4 size bytes: the box starts there.
        if (false === $position || $position < 4) {
            return null;
        }

        $boxStart = $container->payloadOffset + $position - 4;
        $size = (int) unpack('N', substr($payload, $position - 4, 4))[1];

        if ($size < 8 || $boxStart + $size > $container->payloadOffset + $container->payloadLength) {
            return null;
        }

        return new Box($type, $boxStart, $boxStart + 8, $size - 8);
    }

    /**
     * Reads the dimensions from the JPEG's SOF segment.
     *
     * @return array{int, int}
     *
     * @throws CorruptedFileException if no SOF segment can be found
     */
    private function readJpegDimensions(string $jpeg): array
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
                    unpack('n', substr($jpeg, $position + 7, 2))[1],
                    unpack('n', substr($jpeg, $position + 5, 2))[1],
                ];
            }

            $position += 2 + unpack('n', substr($jpeg, $position + 2, 2))[1];
        }

        throw new CorruptedFileException(
            'JPEG without SOF segment: dimensions not found.',
        );
    }
}
