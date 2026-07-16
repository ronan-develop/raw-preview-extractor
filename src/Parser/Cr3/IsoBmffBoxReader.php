<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Parser\Cr3;

use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;

/**
 * Lecture bas niveau d'un conteneur ISO-BMFF (famille MP4/HEIF, dont le CR3).
 *
 * Ce lecteur connaît la **structure** — un arbre de boîtes `[taille][type][contenu]` —
 * et rien de la sémantique : il ignore ce qu'est une preview.
 *
 * **ISO-BMFF est toujours big-endian**, quel que soit l'appareil : contrairement
 * au TIFF, il n'y a pas d'ordre d'octets à détecter.
 *
 * Le fichier est traité comme de l'entrée **non fiable** : chaque taille est
 * validée contre la taille réelle du fichier, la progression du curseur est
 * vérifiée à chaque itération, et la récursion est bornée.
 */
final class IsoBmffBoxReader
{
    /** Taille d'un en-tête de boîte : 4 octets de taille + 4 de type. */
    private const HEADER_LENGTH = 8;

    /** En-tête étendu, quand la taille est portée sur 64 bits. */
    private const EXTENDED_HEADER_LENGTH = 16;

    /** Longueur de l'UUID qui suit le type d'une boîte `uuid`. */
    private const UUID_LENGTH = 16;

    /** Profondeur maximale d'imbrication : un fichier hostile pourrait saturer la pile. */
    private const MAX_DEPTH = 8;

    /**
     * Boîtes dont le contenu est lui-même un arbre de boîtes.
     *
     * Toute autre boîte est une feuille : `mdat` contient des données brutes que
     * l'on prendrait pour des boîtes si on descendait dedans.
     */
    private const CONTAINER_TYPES = ['moov', 'trak', 'mdia', 'minf', 'stbl', 'uuid'];

    /** @var resource */
    private $handle;

    private readonly int $fileSize;

    /**
     * @param string $path chemin du fichier à lire
     *
     * @throws CorruptedFileException si le fichier est illisible
     */
    public function __construct(private readonly string $path)
    {
        if (!is_file($path)) {
            throw new CorruptedFileException(sprintf('Fichier illisible : %s', $path));
        }

        $this->handle = fopen($path, 'rb');
        $this->fileSize = (int) filesize($path);
    }

    public function __destruct()
    {
        fclose($this->handle);
    }

    /**
     * Inventorie les boîtes de premier niveau.
     *
     * @return list<Box>
     *
     * @throws CorruptedFileException si une taille est invalide ou hors bornes
     */
    public function readBoxes(): array
    {
        // Un fichier trop court pour porter ne serait-ce qu'un en-tête n'est pas
        // un conteneur vide : il est tronqué. À l'intérieur d'une boîte en
        // revanche, un reliquat de moins de 8 octets est du padding normal.
        if ($this->fileSize < self::HEADER_LENGTH) {
            throw new CorruptedFileException(sprintf(
                'Fichier tronqué : %d octets, moins qu\'un en-tête de boîte.',
                $this->fileSize,
            ));
        }

        return $this->readBoxesIn(0, $this->fileSize);
    }

    /**
     * Cherche la première boîte du type donné, en descendant dans les conteneurs.
     *
     * @param string $type type sur 4 caractères
     *
     * @throws CorruptedFileException si la structure est invalide
     */
    public function find(string $type): ?Box
    {
        return $this->findIn($this->readBoxes(), $type, 0);
    }

    /**
     * Cherche une boîte `uuid` portant l'UUID donné.
     *
     * Plusieurs boîtes `uuid` distinctes coexistent dans un CR3 : le type ne
     * suffit pas à les identifier, il faut lire les 16 octets qui le suivent.
     *
     * @param string $uuid les 16 octets bruts de l'UUID recherché
     *
     * @throws CorruptedFileException si la structure est invalide
     */
    public function findUuid(string $uuid): ?Box
    {
        return $this->findUuidIn($this->readBoxes(), $uuid, 0);
    }

    /**
     * Inventorie les boîtes filles d'un conteneur.
     *
     * @return list<Box>
     *
     * @throws CorruptedFileException si la structure est invalide
     */
    public function childBoxes(Box $box): array
    {
        return $this->childrenOf($box);
    }

    /**
     * Lit le contenu d'une boîte.
     *
     * @throws CorruptedFileException si la plage sort du fichier
     */
    public function readPayload(Box $box): string
    {
        return $this->readBytes($box->payloadOffset, $box->payloadLength);
    }

    /**
     * Lit `$length` octets bruts à partir de `$offset`.
     *
     * @throws CorruptedFileException si la plage sort du fichier
     */
    public function readBytes(int $offset, int $length): string
    {
        if ($length < 1 || $offset < 0 || $offset + $length > $this->fileSize) {
            throw new CorruptedFileException(sprintf(
                'Lecture hors bornes : %d octets à l\'offset %d (taille du fichier : %d).',
                $length,
                $offset,
                $this->fileSize,
            ));
        }

        fseek($this->handle, $offset);

        return (string) fread($this->handle, $length);
    }

    /**
     * Inventorie les boîtes contenues dans une plage donnée.
     *
     * @return list<Box>
     *
     * @throws CorruptedFileException
     */
    private function readBoxesIn(int $start, int $end): array
    {
        $boxes = [];
        $offset = $start;

        while ($offset + self::HEADER_LENGTH <= $end) {
            $box = $this->readBoxAt($offset, $end);
            $boxes[] = $box;

            // La progression est structurellement garantie : readBoxAt() refuse
            // toute taille inférieure à l'en-tête, donc le curseur avance d'au
            // moins 8 octets à chaque tour.
            $offset = $box->payloadOffset + $box->payloadLength;
        }

        return $boxes;
    }

    /**
     * Décode l'en-tête d'une boîte, en traitant les trois cas particuliers de `size`.
     *
     * @throws CorruptedFileException
     */
    private function readBoxAt(int $offset, int $end): Box
    {
        $header = $this->readBytes($offset, self::HEADER_LENGTH);
        $size = unpack('N', substr($header, 0, 4))[1];
        $type = substr($header, 4, 4);

        // size == 1 : la taille réelle est sur 64 bits, juste après le type.
        if (1 === $size) {
            $size = unpack('J', $this->readBytes($offset + self::HEADER_LENGTH, 8))[1];

            if ($size < self::EXTENDED_HEADER_LENGTH) {
                throw new CorruptedFileException(sprintf(
                    'Taille de boîte 64 bits invalide : %d à l\'offset %d.',
                    $size,
                    $offset,
                ));
            }

            return $this->box($type, $offset, self::EXTENDED_HEADER_LENGTH, $size, $end);
        }

        // size == 0 : la boîte s'étend jusqu'à la fin du fichier.
        if (0 === $size) {
            return $this->box($type, $offset, self::HEADER_LENGTH, $end - $offset, $end);
        }

        if ($size < self::HEADER_LENGTH) {
            throw new CorruptedFileException(sprintf(
                'Taille de boîte invalide : %d à l\'offset %d (minimum 8).',
                $size,
                $offset,
            ));
        }

        return $this->box($type, $offset, self::HEADER_LENGTH, $size, $end);
    }

    /**
     * @throws CorruptedFileException si la boîte déborde de la plage autorisée
     */
    private function box(string $type, int $offset, int $headerLength, int $size, int $end): Box
    {
        if ($offset + $size > $end) {
            throw new CorruptedFileException(sprintf(
                'Boîte « %s » hors bornes : %d octets annoncés à l\'offset %d.',
                $type,
                $size,
                $offset,
            ));
        }

        return new Box($type, $offset, $offset + $headerLength, $size - $headerLength);
    }

    /**
     * Cherche récursivement une boîte `uuid` portant l'UUID donné.
     *
     * Dans un CR3 réel, l'UUID Canon vit sous `moov` et non à la racine : une
     * recherche limitée au premier niveau ne le trouverait jamais.
     *
     * @param list<Box> $boxes
     *
     * @throws CorruptedFileException
     */
    private function findUuidIn(array $boxes, string $uuid, int $depth): ?Box
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }

        foreach ($boxes as $box) {
            if ('uuid' === $box->type
                && $box->payloadLength >= self::UUID_LENGTH
                && $uuid === $this->readBytes($box->payloadOffset, self::UUID_LENGTH)
            ) {
                return $box;
            }

            if (!in_array($box->type, self::CONTAINER_TYPES, true)) {
                continue;
            }

            $found = $this->findUuidIn($this->childrenOf($box), $uuid, $depth + 1);

            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param list<Box> $boxes
     *
     * @throws CorruptedFileException
     */
    private function findIn(array $boxes, string $type, int $depth): ?Box
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }

        foreach ($boxes as $box) {
            if ($box->type === $type) {
                return $box;
            }

            if (!in_array($box->type, self::CONTAINER_TYPES, true)) {
                continue;
            }

            $found = $this->findIn($this->childrenOf($box), $type, $depth + 1);

            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Boîtes filles d'un conteneur.
     *
     * @return list<Box>
     *
     * @throws CorruptedFileException
     */
    private function childrenOf(Box $box): array
    {
        // Une boîte uuid porte 16 octets d'UUID avant son contenu.
        $start = 'uuid' === $box->type
            ? $box->payloadOffset + self::UUID_LENGTH
            : $box->payloadOffset;

        $end = $box->payloadOffset + $box->payloadLength;

        if ($start >= $end) {
            return [];
        }

        try {
            return $this->readBoxesIn($start, $end);
        } catch (CorruptedFileException) {
            // Descendre dans un conteneur est spéculatif : toutes les boîtes
            // `uuid` ne contiennent pas des boîtes. Celle qui porte PRVW dans un
            // CR3 commence par un en-tête propriétaire, dont les octets lus
            // comme un en-tête donnent une taille absurde.
            //
            // Une boîte sans enfants lisibles n'a pas d'enfants — ce n'est pas
            // une corruption du fichier. Les lectures non spéculatives
            // (readBoxes, readPayload, readBytes) restent strictes.
            return [];
        }
    }
}
