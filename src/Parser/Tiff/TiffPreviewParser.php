<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Parser\Tiff;

use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;
use RonanLenouvel\RawPreviewExtractor\Exception\PreviewNotFoundException;
use RonanLenouvel\RawPreviewExtractor\ExtractedPreview;
use RonanLenouvel\RawPreviewExtractor\Format\Format;
use RonanLenouvel\RawPreviewExtractor\Parser\PreviewParserInterface;

/**
 * Extrait la preview JPEG des RAW bâtis sur TIFF : CR2, NEF, ARW et DNG.
 *
 * Un seul parseur couvre les quatre formats. Plutôt que de coder en dur
 * l'organisation d'un constructeur — variable d'une génération d'appareil à
 * l'autre — il parcourt **tous** les IFD, collecte tous les blocs JPEG
 * candidats et retient **le plus grand**.
 *
 * Ce parseur orchestre : il ne lit pas d'octets lui-même, il délègue au
 * {@see TiffReader}.
 */
final class TiffPreviewParser implements PreviewParserInterface
{
    /** Compression JPEG « ancienne » et JPEG au sens TIFF 6.0. */
    private const JPEG_COMPRESSIONS = [6, 7];

    /** Tout JPEG commence par ce marqueur (SOI). */
    private const JPEG_MAGIC = "\xFF\xD8";

    /** Profondeur maximale de récursion dans les sous-IFD. */
    private const MAX_SUB_IFD_DEPTH = 4;

    public function extract(string $path, Format $format): ExtractedPreview
    {
        $reader = new TiffReader($path);
        $candidates = [];

        foreach ($reader->readIfdOffsets() as $offset) {
            $this->collectFromIfd($reader, $offset, $candidates, 0);
        }

        if ([] === $candidates) {
            throw new PreviewNotFoundException(
                sprintf('Aucune preview JPEG dans %s.', basename($path)),
            );
        }

        // La plus grande preview est la plus utile : un RAW en porte souvent
        // plusieurs, de la vignette 160×120 à la pleine résolution.
        usort($candidates, static fn (array $a, array $b): int => $b['length'] <=> $a['length']);

        return $this->buildPreview($reader, $candidates[0], $format, $path);
    }

    /**
     * Collecte les candidats d'un IFD, puis descend dans ses sous-IFD.
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
     * Le chemin courant : le couple JPEGInterchangeFormat / …Length.
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
     * L'autre chemin : StripOffsets / StripByteCounts, si et seulement si le
     * tag Compression annonce du JPEG. Sans ce contrôle, on prendrait les
     * données brutes du capteur pour une preview.
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
    private function buildPreview(TiffReader $reader, array $candidate, Format $format, string $path): ExtractedPreview
    {
        $jpeg = $reader->readBytes($candidate['offset'], $candidate['length']);

        // La structure a désigné ce bloc comme un JPEG : s'il n'en est pas un,
        // c'est le fichier qui ment, pas la preview qui manque.
        if (!str_starts_with($jpeg, self::JPEG_MAGIC)) {
            throw new CorruptedFileException(sprintf(
                'Le bloc désigné à l\'offset %d n\'est pas un JPEG (magic FFD8 absent).',
                $candidate['offset'],
            ));
        }

        [$width, $height] = $this->readJpegDimensions($jpeg, $candidate['offset']);

        return new ExtractedPreview($jpeg, $width, $height, $format);
    }

    /**
     * Lit les dimensions dans le segment SOF du JPEG.
     *
     * Les tags ImageWidth/ImageLength de l'IFD ne conviennent pas : ils décrivent
     * souvent l'image RAW pleine résolution, pas la preview.
     *
     * @return array{int, int}
     *
     * @throws CorruptedFileException
     */
    private function readJpegDimensions(string $jpeg, int $offset): array
    {
        $length = strlen($jpeg);
        $position = 2;

        while ($position + 9 < $length) {
            if ("\xFF" !== $jpeg[$position]) {
                ++$position;

                continue;
            }

            $marker = ord($jpeg[$position + 1]);

            // SOF0 à SOF15, hors DHT (C4), DNL (C8) et DAC (CC) qui partagent la plage.
            if ($marker >= 0xC0 && $marker <= 0xCF && !in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                return [
                    unpack('n', substr($jpeg, $position + 7, 2))[1],
                    unpack('n', substr($jpeg, $position + 5, 2))[1],
                ];
            }

            $segmentLength = unpack('n', substr($jpeg, $position + 2, 2))[1];
            $position += 2 + $segmentLength;
        }

        throw new CorruptedFileException(sprintf(
            'JPEG sans segment SOF à l\'offset %d : dimensions introuvables.',
            $offset,
        ));
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
