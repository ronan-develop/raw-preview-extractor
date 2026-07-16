<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Parser\Tiff;

/**
 * Une entrée d'IFD, valeurs déjà résolues.
 *
 * Value object pur : il ne connaît ni fichier, ni handle, ni endianness. C'est
 * le {@see TiffReader} qui résout les valeurs à la lecture — y compris celles
 * stockées hors de l'entrée — et construit des `IfdEntry` complètes.
 *
 * Cette entrée est donc librement transportable et comparable, ce qu'un objet
 * qui garderait un curseur de fichier ne serait pas.
 */
final readonly class IfdEntry
{
    /**
     * @param int       $tag    identifiant du tag (voir {@see TiffTag})
     * @param int       $type   code du type de donnée TIFF (1 à 12)
     * @param int       $count  nombre de valeurs, tel qu'annoncé par l'entrée
     * @param list<int> $values valeurs numériques résolues ; vide si le type
     *                          est ASCII ou inconnu
     * @param string    $ascii  valeur textuelle, NUL de fin retiré ; chaîne
     *                          vide si le type n'est pas ASCII
     */
    public function __construct(
        public int $tag,
        public int $type,
        public int $count,
        public array $values = [],
        public string $ascii = '',
    ) {
    }

    /**
     * Première valeur numérique de l'entrée, ou null s'il n'y en a pas.
     *
     * Raccourci pour le cas courant : la plupart des tags exploités ici
     * (offsets, tailles, dimensions) portent une valeur unique.
     */
    public function value(): ?int
    {
        return $this->values[0] ?? null;
    }
}
