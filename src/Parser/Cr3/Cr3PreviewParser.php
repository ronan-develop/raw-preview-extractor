<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Parser\Cr3;

use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;
use RonanLenouvel\RawPreviewExtractor\Exception\PreviewNotFoundException;
use RonanLenouvel\RawPreviewExtractor\ExtractedPreview;
use RonanLenouvel\RawPreviewExtractor\Format\Format;
use RonanLenouvel\RawPreviewExtractor\Parser\PreviewParserInterface;

/**
 * Extrait la preview JPEG d'un CR3 (Canon RAW v3, conteneur ISO-BMFF).
 *
 * La preview vit dans la boîte `PRVW` de l'UUID Canon, sous `moov`. `THMB` porte
 * une vignette plus petite, utilisée en repli.
 *
 * **Le CR3 n'a pas de spécification publique** : sa structure vient de
 * rétro-ingénierie communautaire et peut varier selon les modèles. Le code est
 * donc écrit pour être tolérant — il localise le JPEG par son magic plutôt que
 * de s'appuyer sur des décalages fixes.
 *
 * Ce parseur orchestre : il ne lit pas d'octets lui-même, il délègue à
 * {@see IsoBmffBoxReader}.
 */
final class Cr3PreviewParser implements PreviewParserInterface
{
    /**
     * UUID de la boîte Canon portant les métadonnées (PRVW, THMB, CMT1, CMT2).
     *
     * Plusieurs boîtes `uuid` coexistent dans un CR3 : seule celle-ci fait foi.
     */
    private const CANON_UUID = '85c0b687820f11e08111f4ce462b6a48';

    /** Par ordre de préférence : la vraie preview, puis la vignette en repli. */
    private const PREVIEW_BOXES = ['PRVW', 'THMB'];

    /** Marqueur de début de tout JPEG (SOI). */
    private const JPEG_MAGIC = "\xFF\xD8";

    public function extract(string $path, Format $format): ExtractedPreview
    {
        $reader = new IsoBmffBoxReader($path);
        $canon = $reader->findUuid((string) hex2bin(self::CANON_UUID));

        if (null === $canon) {
            throw new PreviewNotFoundException(sprintf(
                'Aucune preview : la boîte UUID Canon est absente de %s.',
                basename($path),
            ));
        }

        foreach (self::PREVIEW_BOXES as $type) {
            $jpeg = $this->jpegFromBox($reader, $canon, $type);

            if (null !== $jpeg) {
                [$width, $height] = $this->readJpegDimensions($jpeg);

                return new ExtractedPreview($jpeg, $width, $height, $format);
            }
        }

        throw new PreviewNotFoundException(sprintf(
            'Aucune preview JPEG dans %s : ni PRVW ni THMB exploitable.',
            basename($path),
        ));
    }

    /**
     * Extrait le JPEG d'une boîte de preview, s'il s'y trouve.
     *
     * @throws CorruptedFileException si la structure est invalide
     */
    private function jpegFromBox(IsoBmffBoxReader $reader, Box $canon, string $type): ?string
    {
        $box = $this->findWithin($reader, $canon, $type);

        if (null === $box) {
            return null;
        }

        $payload = $reader->readPayload($box);

        // PRVW précède son JPEG d'un en-tête propriétaire dont la taille varie
        // selon les modèles et n'est pas documentée. Chercher le magic est plus
        // robuste que de coder un décalage en dur — et le magic est à valider
        // de toute façon.
        $start = strpos($payload, self::JPEG_MAGIC);

        return false === $start ? null : substr($payload, $start);
    }

    /**
     * Cherche une boîte parmi les filles directes de l'UUID Canon.
     *
     * On ne cherche pas dans tout le fichier : une boîte `PRVW` ailleurs
     * n'est pas la preview du CR3, et s'y fier reviendrait à faire confiance
     * à n'importe quelle boîte portant le bon nom.
     *
     * @throws CorruptedFileException si la structure est invalide
     */
    private function findWithin(IsoBmffBoxReader $reader, Box $canon, string $type): ?Box
    {
        foreach ($reader->childBoxes($canon) as $box) {
            if ($box->type === $type) {
                return $box;
            }
        }

        return null;
    }

    /**
     * Lit les dimensions dans le segment SOF du JPEG.
     *
     * @return array{int, int}
     *
     * @throws CorruptedFileException si aucun segment SOF n'est trouvable
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

            // SOF0 à SOF15, hors DHT (C4), DNL (C8) et DAC (CC) qui partagent la plage.
            if ($marker >= 0xC0 && $marker <= 0xCF && !in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                return [
                    unpack('n', substr($jpeg, $position + 7, 2))[1],
                    unpack('n', substr($jpeg, $position + 5, 2))[1],
                ];
            }

            $position += 2 + unpack('n', substr($jpeg, $position + 2, 2))[1];
        }

        throw new CorruptedFileException(
            'JPEG sans segment SOF : dimensions introuvables.',
        );
    }
}
