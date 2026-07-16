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
 * La preview vit dans la boîte `PRVW`, sous une boîte `uuid` dédiée **à la racine**
 * du fichier. `THMB` porte une vignette bien plus petite, sous l'UUID Canon, et
 * sert de repli.
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
     * Emplacements des previews, par ordre de préférence.
     *
     * `PRVW` et `THMB` ne vivent **ni sous le même UUID, ni au même niveau** —
     * structure vérifiée sur Canon EOS R et EOS RP :
     *
     * ```
     * ftyp
     * moov
     *   └── uuid 85c0b687…   → CMT1, CMT2, THMB   (vignette ~15 Ko)
     * uuid eaf42b5e…          → PRVW              (preview ~250 Ko)
     * mdat
     * ```
     *
     * Chercher les deux sous l'UUID Canon ne trouve que la vignette.
     *
     * @var list<array{uuid: string, box: string}>
     */
    private const PREVIEW_LOCATIONS = [
        // La vraie preview, dans sa propre boîte uuid à la racine.
        ['uuid' => 'eaf42b5e1c984b88b9fbb7dc406e4d16', 'box' => 'PRVW'],
        // Repli : la vignette de l'UUID Canon, sous moov.
        ['uuid' => '85c0b687820f11e08111f4ce462b6a48', 'box' => 'THMB'],
    ];

    /** Marqueur de début de tout JPEG (SOI). */
    private const JPEG_MAGIC = "\xFF\xD8";

    /** Longueur de l'UUID qui suit le type d'une boîte `uuid`. */
    private const UUID_LENGTH = 16;

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
    private function jpegFromBox(IsoBmffBoxReader $reader, Box $container, string $type): ?string
    {
        // Certains conteneurs uuid commencent par des octets propriétaires :
        // le parcours normal échoue alors, on retombe sur une recherche par type.
        $box = $this->findWithin($reader, $container, $type)
            ?? $this->findByScanning($reader, $container, $type);

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
     * Cherche une boîte parmi les filles directes d'un conteneur uuid.
     *
     * On ne cherche pas dans tout le fichier : une boîte `PRVW` ailleurs
     * n'est pas la preview du CR3, et s'y fier reviendrait à faire confiance
     * à n'importe quelle boîte portant le bon nom.
     *
     * @throws CorruptedFileException si la structure est invalide
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
     * Cherche une boîte dans le contenu brut d'un conteneur, sans supposer que
     * celui-ci commence par une boîte.
     *
     * La boîte `uuid` qui porte `PRVW` insère **8 octets propriétaires** entre
     * l'UUID et la première boîte — vérifié sur EOS R et EOS RP. Sa taille n'est
     * documentée nulle part, et rien ne garantit qu'elle soit la même partout.
     * On localise donc le type recherché dans le contenu, plutôt que de coder un
     * décalage en dur.
     *
     * @throws CorruptedFileException si la structure est invalide
     */
    private function findByScanning(IsoBmffBoxReader $reader, Box $container, string $type): ?Box
    {
        $payload = $reader->readPayload($container);
        $position = strpos($payload, $type, self::UUID_LENGTH);

        // Le type est précédé des 4 octets de taille : la boîte commence là.
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
