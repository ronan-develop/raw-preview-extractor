<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Parser\Tiff;

use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;

/**
 * Une entrée d'IFD : 12 octets de structure fixe décrivant un tag et sa valeur.
 *
 * L'entrée porte ses quatre champs bruts ; la lecture de la valeur est déléguée
 * au {@see TiffReader}, seul détenteur du handle de fichier et de l'endianness.
 *
 * **Règle des 4 octets** — si `taille_du_type × count <= 4`, le champ final
 * contient la valeur elle-même, cadrée à gauche ; sinon il contient un offset
 * absolu vers la valeur, ailleurs dans le fichier.
 */
final readonly class IfdEntry
{
    /** Taille en octets de chaque type TIFF, indexée par code de type. */
    private const TYPE_SIZES = [
        1 => 1,   // BYTE
        2 => 1,   // ASCII
        3 => 2,   // SHORT
        4 => 4,   // LONG
        5 => 8,   // RATIONAL
        6 => 1,   // SBYTE
        7 => 1,   // UNDEFINED
        8 => 2,   // SSHORT
        9 => 4,   // SLONG
        10 => 8,  // SRATIONAL
        11 => 4,  // FLOAT
        12 => 8,  // DOUBLE
    ];

    /**
     * @param int    $tag           identifiant du tag (voir {@see TiffTag})
     * @param int    $type          code du type de donnée (1 à 12)
     * @param int    $count         nombre de **valeurs**, pas d'octets
     * @param string $valueOrOffset les 4 octets bruts du champ Value/Offset
     */
    public function __construct(
        public int $tag,
        public int $type,
        public int $count,
        public string $valueOrOffset,
    ) {
    }

    /**
     * Taille totale des données de cette entrée, en octets.
     */
    public function byteLength(): int
    {
        return $this->typeSize() * $this->count;
    }

    /**
     * Les données tiennent-elles dans les 4 octets du champ ?
     */
    public function isInline(): bool
    {
        return $this->byteLength() <= 4;
    }

    /**
     * Première valeur de l'entrée, ou null si le type est inconnu.
     *
     * Un type inconnu n'est pas une corruption : l'entrée reste lisible, seule
     * sa valeur est indéterminable.
     *
     * @throws CorruptedFileException si un offset indirect est hors bornes
     */
    public function value(TiffReader $reader): ?int
    {
        $values = $this->values($reader);

        return $values[0] ?? null;
    }

    /**
     * Toutes les valeurs numériques de l'entrée.
     *
     * @return list<int> vide si le type est inconnu ou non numérique
     *
     * @throws CorruptedFileException si l'offset est hors bornes ou la taille absurde
     */
    public function values(TiffReader $reader): array
    {
        $size = self::TYPE_SIZES[$this->type] ?? null;

        if (null === $size || 2 === $this->type) {
            return [];
        }

        $bytes = $this->rawBytes($reader);

        return $reader->unpackIntegers($bytes, $size, $this->count);
    }

    /**
     * Valeur ASCII de l'entrée, NUL de fin retiré.
     *
     * @throws CorruptedFileException si l'offset est hors bornes
     */
    public function asciiValue(TiffReader $reader): ?string
    {
        if (2 !== $this->type) {
            return null;
        }

        return rtrim($this->rawBytes($reader), "\x00");
    }

    /**
     * Octets bruts de la valeur, qu'elle soit inline ou référencée par offset.
     *
     * @throws CorruptedFileException si l'offset est hors bornes ou la taille absurde
     */
    public function rawBytes(TiffReader $reader): string
    {
        $length = $this->byteLength();

        if ($length <= 0) {
            throw new CorruptedFileException(
                sprintf('Entrée d\'IFD 0x%04X : taille de données invalide (%d).', $this->tag, $length),
            );
        }

        if ($this->isInline()) {
            return substr($this->valueOrOffset, 0, $length);
        }

        return $reader->readBytes($reader->unpackLong($this->valueOrOffset), $length);
    }

    private function typeSize(): int
    {
        return self::TYPE_SIZES[$this->type] ?? 0;
    }
}
