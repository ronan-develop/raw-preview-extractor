<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Format;

/**
 * Détecte le format RAW par lecture de la signature binaire du fichier.
 *
 * L'extension du fichier n'est jamais consultée : un CR2 renommé en `.jpg`
 * reste détecté comme un CR2, et un `.cr2` qui n'en est pas un est rejeté.
 *
 * Deux familles de conteneurs sont reconnues :
 *  - TIFF 6.0 (CR2, NEF, ARW, DNG), discriminé par la signature Canon `CR`
 *    ou par les tags DNGVersion / Make de l'IFD0 ;
 *  - ISO-BMFF (CR3), discriminé par la boîte `ftyp` et le brand `crx `.
 */
final class FormatDetector implements FormatDetectorInterface
{
    /** Octets suffisants pour couvrir l'en-tête TIFF, la signature CR2 et le ftyp d'un CR3. */
    private const HEADER_BYTES = 16;

    /** Nombre magique du format TIFF, lu selon l'endianness du fichier. */
    private const TIFF_MAGIC = 42;

    /** Au-delà, un IFD0 aussi long trahit un fichier corrompu ou hostile. */
    private const MAX_IFD0_ENTRIES = 512;

    private const TAG_MAKE = 0x010F;
    private const TAG_DNG_VERSION = 0xC612;

    public function detect(string $path): ?Format
    {
        $handle = @fopen($path, 'rb');

        if (false === $handle) {
            return null;
        }

        try {
            $header = fread($handle, self::HEADER_BYTES);

            // fread renvoie moins que demandé en fin de fichier : sans ce contrôle,
            // unpack() lirait des octets qui n'existent pas.
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
     * CR3 : boîte `ftyp` en tête, brand majeur `crx ` aux octets 8-11.
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

        // CR2 : signature « CR » suivie de la version, juste après l'en-tête TIFF.
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
     * @return array{string, string}|null couple de formats unpack() {court, long}
     */
    private function readEndianness(string $header): ?array
    {
        return match (substr($header, 0, 2)) {
            'II' => ['v', 'V'],  // little-endian
            'MM' => ['n', 'N'],  // big-endian
            default => null,     // ni l'un ni l'autre : pas un TIFF
        };
    }

    /**
     * Parcourt les entrées de l'IFD0 à la recherche d'un tag discriminant.
     *
     * @param resource $handle
     */
    private function detectFromIfd0($handle, string $shortFormat, string $longFormat, int $ifdOffset): ?Format
    {
        if (-1 === fseek($handle, $ifdOffset)) {
            return null;
        }

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

            // DNGVersion suffit : le format est normalisé par Adobe.
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
     * Lit la valeur du tag Make, stockée hors de l'entrée dès qu'elle dépasse
     * 4 octets — ce qui est toujours le cas d'un nom de fabricant.
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

        // La position courante doit être restaurée : la boucle appelante
        // continue de lire les entrées séquentiellement.
        $position = ftell($handle);

        if (false === $position || -1 === fseek($handle, $offset)) {
            return null;
        }

        $value = fread($handle, $count);
        fseek($handle, $position);

        if (!is_string($value)) {
            return null;
        }

        return rtrim($value, "\x00");
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
     * unpack() renvoie false sur données trop courtes ; on normalise en null
     * plutôt que de laisser un false se propager silencieusement.
     */
    private function unpackInt(string $format, string $bytes): ?int
    {
        $expected = 'v' === $format || 'n' === $format ? 2 : 4;

        if (strlen($bytes) !== $expected) {
            return null;
        }

        $result = @unpack($format, $bytes);

        return false === $result ? null : $result[1];
    }
}
