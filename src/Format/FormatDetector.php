<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Format;

/**
 * Detects the RAW format by reading the file's binary signature.
 *
 * The file extension is never consulted: a CR2 renamed to `.jpg` is still
 * detected as a CR2, and a `.cr2` that is not one is rejected.
 *
 * Two container families are recognised:
 *  - TIFF 6.0 (CR2, NEF, ARW, DNG), discriminated by the Canon `CR` signature
 *    or by the DNGVersion / Make tags of the IFD0;
 *  - ISO-BMFF (CR3), discriminated by the `ftyp` box and the `crx ` brand.
 */
final class FormatDetector implements FormatDetectorInterface
{
    /** Bytes enough to cover the TIFF header, the CR2 signature and a CR3's ftyp. */
    private const HEADER_BYTES = 16;

    /** Magic number of the TIFF format, read according to the file's endianness. */
    private const TIFF_MAGIC = 42;

    /** Beyond this, an IFD0 that long betrays a corrupted or hostile file. */
    private const MAX_IFD0_ENTRIES = 512;

    private const TAG_MAKE = 0x010F;
    private const TAG_DNG_VERSION = 0xC612;

    public function detect(string $path): ?Format
    {
        // fopen() succeeds on a directory: it is fread() that would fail next,
        // emitting a notice. We rule the case out here rather than muzzling it.
        if (!is_file($path)) {
            return null;
        }

        // is_file() has already ruled out the missing file and the directory.
        $handle = fopen($path, 'rb');

        try {
            $header = fread($handle, self::HEADER_BYTES);

            // fread returns less than requested at end of file: without this check,
            // unpack() would read bytes that do not exist.
            if (!is_string($header) || strlen($header) < 8) {
                return null;
            }

            return $this->detectIsoBmff($header)
                ?? $this->detectTiff($handle, $header);
        } finally {
            fclose($handle);
        }
    }

    /**
     * CR3: `ftyp` box up front, major brand `crx ` at bytes 8-11.
     */
    private function detectIsoBmff(string $header): ?Format
    {
        if (strlen($header) < 12 || 'ftyp' !== substr($header, 4, 4)) {
            return null;
        }

        return 'crx ' === substr($header, 8, 4) ? Format::CR3 : null;
    }

    /**
     * @param resource $handle
     */
    private function detectTiff($handle, string $header): ?Format
    {
        $endianness = $this->readEndianness($header);

        if (null === $endianness) {
            return null;
        }

        [$shortFormat, $longFormat] = $endianness;

        if (self::TIFF_MAGIC !== $this->unpackInt($shortFormat, substr($header, 2, 2))) {
            return null;
        }

        // CR2: "CR" signature followed by the version, right after the TIFF header.
        if (strlen($header) >= 10 && 'CR' === substr($header, 8, 2)) {
            return Format::CR2;
        }

        $ifdOffset = $this->unpackInt($longFormat, substr($header, 4, 4));

        if (null === $ifdOffset || $ifdOffset < 8) {
            return null;
        }

        return $this->detectFromIfd0($handle, $shortFormat, $longFormat, $ifdOffset);
    }

    /**
     * @return array{string, string}|null pair of unpack() formats {short, long}
     */
    private function readEndianness(string $header): ?array
    {
        return match (substr($header, 0, 2)) {
            'II' => ['v', 'V'],  // little-endian
            'MM' => ['n', 'N'],  // big-endian
            default => null,     // neither one: not a TIFF
        };
    }

    /**
     * Walks the IFD0 entries looking for a discriminating tag.
     *
     * @param resource $handle
     */
    private function detectFromIfd0($handle, string $shortFormat, string $longFormat, int $ifdOffset): ?Format
    {
        // fseek past the end succeeds on a file: it is the next read that
        // returns an empty string, which is handled right after.
        fseek($handle, $ifdOffset);

        $countBytes = fread($handle, 2);

        if (!is_string($countBytes) || 2 !== strlen($countBytes)) {
            return null;
        }

        $entryCount = $this->unpackInt($shortFormat, $countBytes);

        if (null === $entryCount || $entryCount < 1 || $entryCount > self::MAX_IFD0_ENTRIES) {
            return null;
        }

        $makeValue = null;

        for ($i = 0; $i < $entryCount; ++$i) {
            $entry = fread($handle, 12);

            if (!is_string($entry) || 12 !== strlen($entry)) {
                return null;
            }

            $tag = $this->unpackInt($shortFormat, substr($entry, 0, 2));

            // DNGVersion is enough: the format is standardised by Adobe.
            if (self::TAG_DNG_VERSION === $tag) {
                return Format::DNG;
            }

            if (self::TAG_MAKE === $tag && null === $makeValue) {
                $makeValue = $this->readMake($handle, $longFormat, $entry);
            }
        }

        return $this->formatFromMake($makeValue);
    }

    /**
     * Reads the value of the Make tag, stored outside the entry as soon as it
     * exceeds 4 bytes — which is always the case for a manufacturer name.
     *
     * @param resource $handle
     */
    private function readMake($handle, string $longFormat, string $entry): ?string
    {
        $count = $this->unpackInt($longFormat, substr($entry, 4, 4));
        $offset = $this->unpackInt($longFormat, substr($entry, 8, 4));

        if (null === $count || null === $offset || $count < 1 || $count > 256) {
            return null;
        }

        // The current position must be restored: the calling loop keeps reading
        // the entries sequentially. On a local file stream, ftell/fseek do not
        // fail — no need to guard against it.
        $position = (int) ftell($handle);
        fseek($handle, $offset);

        $value = (string) fread($handle, $count);
        fseek($handle, $position);

        // An out-of-bounds offset does not make fseek fail: it is the read that
        // returns an empty string.
        return '' === $value ? null : rtrim($value, "\x00");
    }

    private function formatFromMake(?string $make): ?Format
    {
        if (null === $make) {
            return null;
        }

        $make = strtoupper($make);

        return match (true) {
            str_contains($make, 'NIKON') => Format::NEF,
            str_contains($make, 'SONY') => Format::ARW,
            str_contains($make, 'CANON') => Format::CR2,
            default => null,
        };
    }

    /**
     * Checks the length before unpack(): a fread at end of file returns fewer
     * bytes than requested, silently. Once the length is guaranteed, unpack()
     * can no longer fail.
     */
    private function unpackInt(string $format, string $bytes): ?int
    {
        // The bytes come either from the header (8 bytes guaranteed), or from a
        // fread whose length is checked by the caller: unpack cannot work short.
        return unpack($format, $bytes)[1];
    }
}
