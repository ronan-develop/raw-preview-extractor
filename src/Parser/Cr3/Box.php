<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Parser\Cr3;

/**
 * Une boîte ISO-BMFF, localisée dans le fichier.
 *
 * La boîte porte sa position et sa taille, jamais son contenu : un `mdat` pèse
 * plusieurs dizaines de mégaoctets, on ne le charge pas pour l'inventorier.
 * C'est {@see IsoBmffBoxReader::readPayload()} qui lit les octets à la demande.
 */
final readonly class Box
{
    /**
     * @param string $type          type sur 4 caractères ASCII (`ftyp`, `moov`, `uuid`…)
     * @param int    $offset        position de la boîte dans le fichier, en-tête compris
     * @param int    $payloadOffset position du contenu, après l'en-tête
     * @param int    $payloadLength taille du contenu, en octets
     */
    public function __construct(
        public string $type,
        public int $offset,
        public int $payloadOffset,
        public int $payloadLength,
    ) {
    }
}
