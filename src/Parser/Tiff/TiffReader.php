<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Parser\Tiff;

use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;

/**
 * Lecture bas niveau d'un conteneur TIFF 6.0.
 *
 * Ce lecteur connaît la **structure** du conteneur — en-tête, chaîne d'IFD,
 * entrées typées — et rien de la sémantique du contenu : il ignore ce qu'est
 * une preview. C'est cette frontière qui le rend testable sans fichier RAW réel.
 *
 * Toutes les lectures traitent le fichier comme de l'entrée **non fiable** :
 * chaque offset est validé contre la taille réelle, chaque `fread` est vérifié
 * en longueur avant `unpack`, et la chaîne d'IFD est protégée contre les boucles.
 */
final class TiffReader
{
    private const HEADER_LENGTH = 8;
    private const TIFF_MAGIC = 42;
    private const ENTRY_LENGTH = 12;

    /** Au-delà, un IFD trahit un fichier corrompu ou hostile. */
    private const MAX_ENTRIES_PER_IFD = 4096;

    /** Garde-fou contre une chaîne d'IFD artificiellement longue. */
    private const MAX_IFD_CHAIN = 64;

    /**
     * Au-delà, une valeur n'est pas résolue — l'entrée reste lisible.
     *
     * Aucun tag exploité par ce package n'approche cette taille : ce sont des
     * offsets, des tailles, des dimensions, un nom de fabricant. Les gros blocs
     * sont des métadonnées propriétaires (`MakerNote` pèse couramment 75 Ko dans
     * un CR2) que l'on traverse sans jamais les lire.
     *
     * Ne pas les résoudre évite deux choses : gaspiller la lecture, et rejeter
     * un fichier parfaitement valide — ce qui arrivait au Canon 5D de 2005.
     */
    private const MAX_RESOLVED_VALUE_LENGTH = 65536;

    /** Taille en octets de chaque type TIFF 6.0, indexée par code de type. */
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

    /** @var resource */
    private $handle;

    private readonly bool $bigEndian;
    private readonly int $fileSize;
    private readonly int $firstIfdOffset;

    /**
     * @param string $path chemin du fichier TIFF à lire
     *
     * @throws CorruptedFileException si le fichier est illisible ou n'est pas un TIFF
     */
    public function __construct(private readonly string $path)
    {
        if (!is_file($path)) {
            throw new CorruptedFileException(sprintf('Fichier illisible : %s', $path));
        }

        // is_file() a déjà écarté l'absent et le répertoire : fopen ne peut
        // plus échouer que sur un problème de droits, que le @ absorberait
        // silencieusement — on le laisse donc remonter.
        $this->handle = fopen($path, 'rb');
        $this->fileSize = (int) filesize($path);

        $header = (string) fread($this->handle, self::HEADER_LENGTH);

        if (self::HEADER_LENGTH !== strlen($header)) {
            throw new CorruptedFileException('En-tête TIFF tronqué : moins de 8 octets.');
        }

        $this->bigEndian = match (substr($header, 0, 2)) {
            'II' => false,
            'MM' => true,
            default => throw new CorruptedFileException(
                'Ordre d\'octets inconnu : ni « II » ni « MM ».',
            ),
        };

        $magic = $this->unpackShort(substr($header, 2, 2));

        if (self::TIFF_MAGIC !== $magic) {
            throw new CorruptedFileException(
                sprintf('Nombre magique TIFF invalide : %d au lieu de 42.', $magic),
            );
        }

        $this->firstIfdOffset = $this->unpackLong(substr($header, 4, 4));
    }

    public function __destruct()
    {
        fclose($this->handle);
    }

    /**
     * Le fichier est-il big-endian (« MM ») ?
     */
    public function isBigEndian(): bool
    {
        return $this->bigEndian;
    }

    /**
     * Parcourt la chaîne d'IFD et renvoie les offsets rencontrés, dans l'ordre.
     *
     * Un IFD déjà visité clôt le parcours : c'est le garde-fou anti-boucle. Un
     * fichier corrompu — ou malveillant — peut faire pointer un IFD sur lui-même,
     * ce qu'aucun fichier réel ne fait.
     *
     * @return list<int>
     *
     * @throws CorruptedFileException si un offset de la chaîne est hors bornes
     */
    public function readIfdOffsets(): array
    {
        $offsets = [];
        $visited = [];
        $offset = $this->firstIfdOffset;

        while (0 !== $offset && count($offsets) < self::MAX_IFD_CHAIN) {
            if (isset($visited[$offset])) {
                break;
            }

            $visited[$offset] = true;
            $offsets[] = $offset;
            $offset = $this->readNextIfdOffset($offset);
        }

        return $offsets;
    }

    /**
     * Lit les entrées d'un IFD situé à l'offset donné.
     *
     * @return list<IfdEntry>
     *
     * @throws CorruptedFileException si l'offset est hors bornes ou l'IFD tronqué
     */
    public function readIfd(int $offset): array
    {
        $count = $this->readEntryCount($offset);
        $entries = [];

        for ($i = 0; $i < $count; ++$i) {
            $entries[] = $this->readEntry(
                $this->readBytes($offset + 2 + $i * self::ENTRY_LENGTH, self::ENTRY_LENGTH),
            );
        }

        return $entries;
    }

    /**
     * Construit une entrée à partir de ses 12 octets, valeurs résolues comprises.
     *
     * @throws CorruptedFileException si un offset indirect est hors bornes
     */
    private function readEntry(string $bytes): IfdEntry
    {
        $tag = $this->unpackShort(substr($bytes, 0, 2));
        $type = $this->unpackShort(substr($bytes, 2, 2));
        $count = $this->unpackLong(substr($bytes, 4, 4));
        $field = substr($bytes, 8, 4);

        $size = self::TYPE_SIZES[$type] ?? null;

        // Un type inconnu n'est pas une corruption : l'entrée reste lisible,
        // seule sa valeur est indéterminable.
        if (null === $size || $count < 1) {
            return new IfdEntry($tag, $type, $count);
        }

        $length = $size * $count;

        // Une valeur ne peut pas être plus grande que le fichier qui la porte :
        // c'est une taille absurde, donc une structure qui ment.
        if ($length > $this->fileSize) {
            throw new CorruptedFileException(sprintf(
                'Entrée 0x%04X : %d octets annoncés, plus que le fichier entier (%d).',
                $tag,
                $length,
                $this->fileSize,
            ));
        }

        // Trop gros pour être un tag que ce package exploite : on garde l'entrée
        // — elle reste traversable — mais on ne lit pas sa valeur. Un MakerNote
        // de 75 Ko n'est pas une corruption, c'est une métadonnée qui ne nous
        // regarde pas.
        if ($length > self::MAX_RESOLVED_VALUE_LENGTH) {
            return new IfdEntry($tag, $type, $count);
        }

        // Règle des 4 octets : au-delà, le champ porte un offset absolu et non
        // la valeur elle-même. S'y tromper donne des offsets qui ressemblent à
        // des données valides.
        $raw = $length <= 4
            ? substr($field, 0, $length)
            : $this->readBytes($this->unpackLong($field), $length);

        if (2 === $type) {
            return new IfdEntry($tag, $type, $count, [], rtrim($raw, "\x00"));
        }

        return new IfdEntry($tag, $type, $count, $this->unpackIntegers($raw, $size, $count));
    }

    /**
     * Lit `$length` octets bruts à partir de `$offset`.
     *
     * @throws CorruptedFileException si la plage sort du fichier
     */
    public function readBytes(int $offset, int $length): string
    {
        if ($length <= 0) {
            throw new CorruptedFileException(sprintf('Longueur de lecture invalide : %d.', $length));
        }

        if ($offset < 0 || $offset + $length > $this->fileSize) {
            throw new CorruptedFileException(sprintf(
                'Lecture hors bornes : %d octets à l\'offset %d (taille du fichier : %d).',
                $length,
                $offset,
                $this->fileSize,
            ));
        }

        fseek($this->handle, $offset);

        // La plage est déjà bornée contre fileSize : fread rend exactement
        // $length octets.
        return (string) fread($this->handle, $length);
    }

    /**
     * Décode une suite d'entiers selon l'endianness du fichier.
     *
     * Les octets sont toujours fournis par readBytes(), qui garantit déjà leur
     * longueur : inutile de la revérifier ici. TYPE_SIZES ne contient que des
     * tailles de 1, 2, 4 ou 8 octets.
     *
     * @return list<int>
     */
    private function unpackIntegers(string $bytes, int $size, int $count): array
    {
        $values = [];

        for ($i = 0; $i < $count; ++$i) {
            $chunk = substr($bytes, $i * $size, $size);

            $values[] = match ($size) {
                1 => ord($chunk),
                2 => $this->unpackShort($chunk),
                // RATIONAL et DOUBLE (8 octets) : seuls les 4 premiers nous
                // intéressent — aucun tag exploité ici n'a besoin du reste.
                default => $this->unpackLong(substr($chunk, 0, 4)),
            };
        }

        return $values;
    }

    /**
     * Décode un entier 16 bits selon l'endianness du fichier.
     */
    private function unpackShort(string $bytes): int
    {
        return unpack($this->bigEndian ? 'n' : 'v', $bytes)[1];
    }

    /**
     * Décode un entier 32 bits selon l'endianness du fichier.
     *
     * @throws CorruptedFileException si les octets fournis ne font pas 4 octets
     */
    private function unpackLong(string $bytes): int
    {
        return unpack($this->bigEndian ? 'N' : 'V', $bytes)[1];
    }

    /**
     * @throws CorruptedFileException si l'offset est invalide ou le compte absurde
     */
    private function readEntryCount(int $offset): int
    {
        if ($offset < self::HEADER_LENGTH) {
            throw new CorruptedFileException(sprintf(
                'Offset d\'IFD invalide : %d chevauche l\'en-tête.',
                $offset,
            ));
        }

        $count = $this->unpackShort($this->readBytes($offset, 2));

        if ($count > self::MAX_ENTRIES_PER_IFD) {
            throw new CorruptedFileException(sprintf(
                'Nombre d\'entrées d\'IFD absurde : %d.',
                $count,
            ));
        }

        // Le fichier doit contenir toutes les entrées annoncées, plus le lien suivant.
        $required = $offset + 2 + $count * self::ENTRY_LENGTH + 4;

        if ($required > $this->fileSize) {
            throw new CorruptedFileException(sprintf(
                'IFD tronqué : %d entrées annoncées à l\'offset %d dépassent la taille du fichier.',
                $count,
                $offset,
            ));
        }

        return $count;
    }

    /**
     * @throws CorruptedFileException si l'IFD est tronqué
     */
    private function readNextIfdOffset(int $offset): int
    {
        $count = $this->readEntryCount($offset);

        return $this->unpackLong(
            $this->readBytes($offset + 2 + $count * self::ENTRY_LENGTH, 4),
        );
    }
}
