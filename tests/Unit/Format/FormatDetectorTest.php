<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Tests\Unit\Format;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RonanLenouvel\RawPreviewExtractor\Format\Format;
use RonanLenouvel\RawPreviewExtractor\Format\FormatDetector;

#[CoversClass(FormatDetector::class)]
final class FormatDetectorTest extends TestCase
{
    private const TAG_MAKE = 0x010F;

    private FormatDetector $detector;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->detector = new FormatDetector();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        $this->tempFiles = [];
    }

    public function testDetectsCr2(): void
    {
        // CR2 : en-tête TIFF little-endian, puis la signature « CR » propre à Canon
        // aux octets 8-9, suivie de la version majeure du format.
        $bytes = "II" . pack('v', 42) . pack('V', 16) . "CR\x02\x00" . str_repeat("\x00", 8);

        self::assertSame(Format::CR2, $this->detector->detect($this->file($bytes)));
    }

    public function testDetectsCr3(): void
    {
        // CR3 : conteneur ISO-BMFF — boîte ftyp, puis le brand majeur « crx »
        // (avec l'espace final : c'est un identifiant sur exactement 4 octets).
        $bytes = pack('N', 24) . "ftyp" . "crx " . pack('N', 1) . "crx isom";

        self::assertSame(Format::CR3, $this->detector->detect($this->file($bytes)));
    }

    public function testDetectsDngByDngVersionTag(): void
    {
        // DNG : TIFF portant le tag DNGVersion (0xC612) dans l'IFD0.
        self::assertSame(
            Format::DNG,
            $this->detector->detect($this->file($this->tiffWithTag(0xC612, "\x01\x04\x00\x00"))),
        );
    }

    public function testDetectsNefByMakeTag(): void
    {
        // NEF : TIFF dont le tag Make (0x010F) vaut « NIKON ».
        self::assertSame(
            Format::NEF,
            $this->detector->detect($this->file($this->tiffWithMake('NIKON CORPORATION'))),
        );
    }

    public function testDetectsArwByMakeTag(): void
    {
        // ARW : TIFF dont le tag Make vaut « SONY ».
        self::assertSame(
            Format::ARW,
            $this->detector->detect($this->file($this->tiffWithMake('SONY'))),
        );
    }

    public function testReturnsNullForNonRaw(): void
    {
        // Un JPEG normal : magic FFD8, aucune structure TIFF ni ISO-BMFF.
        $jpeg = "\xFF\xD8\xFF\xE0" . pack('n', 16) . "JFIF\x00" . str_repeat("\x00", 32);

        self::assertNull($this->detector->detect($this->file($jpeg)));
    }

    #[DataProvider('provideNonRawBytes')]
    public function testReturnsNullForVariousNonRawInputs(string $label, string $bytes): void
    {
        self::assertNull($this->detector->detect($this->file($bytes)), $label);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNonRawBytes(): iterable
    {
        // Un fichier plus court que l'en-tête TIFF ne doit pas faire lever unpack().
        yield 'fichier vide' => ['fichier vide', ''];
        yield 'deux octets' => ['plus court que l en-tête TIFF', 'II'];
        yield 'texte brut' => ['texte brut', 'Ceci est un fichier texte, pas un RAW.'];
        yield 'PNG' => ['PNG', "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 16)];

        // En-tête TIFF valide mais magic incorrect (43 au lieu de 42) : pas un TIFF.
        yield 'magic TIFF faux' => [
            'magic TIFF faux',
            "II" . pack('v', 43) . pack('V', 8) . str_repeat("\x00", 8),
        ];

        // ISO-BMFF valide mais brand inconnu : c'est un MP4, pas un CR3.
        yield 'ISO-BMFF non-crx' => [
            'ISO-BMFF avec brand isom',
            pack('N', 20) . "ftyp" . "isom" . pack('N', 512) . "isom",
        ];
    }

    public function testDetectsBigEndianTiff(): void
    {
        // Un TIFF big-endian (« MM ») doit être lu aussi bien qu'un little-endian :
        // l'endianness gouverne toute lecture d'entier, y compris celle des tags.
        $value = "NIKON\x00";
        $entry = pack('n', self::TAG_MAKE)
            . pack('n', 2)              // type ASCII
            . pack('N', strlen($value))
            . pack('N', 26);            // offset indirect

        $bytes = "MM" . pack('n', 42) . pack('N', 8)
            . pack('n', 1) . $entry . pack('N', 0) . $value;

        self::assertSame(Format::NEF, $this->detector->detect($this->file($bytes)));
    }

    public function testReturnsNullWhenIfdOffsetPointsBeyondFile(): void
    {
        // Offset du 1er IFD au-delà de la fin : fseek réussit (PHP l'autorise),
        // mais la lecture qui suit ne rend rien. Ne doit pas lever.
        $bytes = "II" . pack('v', 42) . pack('V', 9999) . str_repeat("\x00", 8);

        self::assertNull($this->detector->detect($this->file($bytes)));
    }

    public function testReturnsNullWhenIfdOffsetIsInsideHeader(): void
    {
        // Un IFD ne peut pas commencer avant l'octet 8 : il chevaucherait l'en-tête.
        $bytes = "II" . pack('v', 42) . pack('V', 2) . str_repeat("\x00", 8);

        self::assertNull($this->detector->detect($this->file($bytes)));
    }

    public function testReturnsNullWhenIfdIsTruncatedMidEntry(): void
    {
        // L'IFD annonce 3 entrées mais le fichier s'arrête au milieu de la première :
        // fread rend moins que les 12 octets attendus, silencieusement.
        $bytes = "II" . pack('v', 42) . pack('V', 8) . pack('v', 3) . "\x0F\x01\x02";

        self::assertNull($this->detector->detect($this->file($bytes)));
    }

    public function testReturnsNullWhenIfdEntryCountIsZero(): void
    {
        $bytes = "II" . pack('v', 42) . pack('V', 8) . pack('v', 0) . pack('V', 0);

        self::assertNull($this->detector->detect($this->file($bytes)));
    }

    public function testReturnsNullWhenIfdEntryCountIsAbsurd(): void
    {
        // 65535 entrées dans un fichier de 14 octets : fichier corrompu ou hostile.
        // Sans plafond, on bouclerait 65535 fois sur des fread vides.
        $bytes = "II" . pack('v', 42) . pack('V', 8) . pack('v', 65535) . pack('V', 0);

        self::assertNull($this->detector->detect($this->file($bytes)));
    }

    public function testReturnsNullWhenMakeOffsetPointsBeyondFile(): void
    {
        // Le tag Make existe, mais son offset indirect pointe hors du fichier.
        $entry = pack('v', self::TAG_MAKE)
            . pack('v', 2)
            . pack('V', 6)
            . pack('V', 9999);          // offset hors bornes

        $bytes = "II" . pack('v', 42) . pack('V', 8)
            . pack('v', 1) . $entry . pack('V', 0);

        self::assertNull($this->detector->detect($this->file($bytes)));
    }

    public function testReturnsNullWhenMakeCountIsAbsurd(): void
    {
        // Un nom de fabricant de 100 000 octets n'existe pas : refuser d'allouer.
        $entry = pack('v', self::TAG_MAKE)
            . pack('v', 2)
            . pack('V', 100000)
            . pack('V', 26);

        $bytes = "II" . pack('v', 42) . pack('V', 8)
            . pack('v', 1) . $entry . pack('V', 0) . "NIKON\x00";

        self::assertNull($this->detector->detect($this->file($bytes)));
    }

    public function testReturnsNullForUnknownMake(): void
    {
        // TIFF valide, tag Make présent, mais fabricant non supporté :
        // ce n'est pas un RAW que ce package sait traiter.
        self::assertNull($this->detector->detect($this->file($this->tiffWithMake('PENTAX'))));
    }

    public function testDetectsCanonMakeAsCr2(): void
    {
        // Un TIFF Canon sans la signature « CR » (CR2 ancien ou variante) :
        // le tag Make prend le relais.
        self::assertSame(
            Format::CR2,
            $this->detector->detect($this->file($this->tiffWithMake('Canon'))),
        );
    }

    public function testIgnoresUnrelatedTagsBeforeMake(): void
    {
        // Le tag discriminant n'est pas forcément le premier : la boucle doit
        // traverser les entrées non pertinentes sans s'arrêter.
        $value = "SONY\x00";

        $filler = pack('v', 0x0100) . pack('v', 3) . pack('V', 1) . pack('V', 4000);
        $make = pack('v', self::TAG_MAKE) . pack('v', 2) . pack('V', strlen($value)) . pack('V', 38);

        $bytes = "II" . pack('v', 42) . pack('V', 8)
            . pack('v', 2) . $filler . $make . pack('V', 0) . $value;

        self::assertSame(Format::ARW, $this->detector->detect($this->file($bytes)));
    }

    public function testReturnsNullWhenHeaderIsTooShortForIfdOffset(): void
    {
        // 9 octets : assez pour passer le contrôle des 8 octets d'en-tête, mais
        // le champ « offset du 1er IFD » est amputé. unpackInt doit rendre null
        // plutôt que de laisser unpack() travailler sur des octets manquants.
        $bytes = 'II' . pack('v', 42) . "\x08\x00\x00";

        self::assertNull($this->detector->detect($this->file($bytes)));
    }

    public function testReturnsNullForDirectory(): void
    {
        // fopen sur un répertoire réussit sur certains systèmes, mais fread échoue.
        self::assertNull($this->detector->detect(sys_get_temp_dir()));
    }

    public function testReturnsNullForFtypBoxTooShortForBrand(): void
    {
        // Boîte ftyp annoncée mais tronquée avant le brand : moins de 12 octets.
        $bytes = pack('N', 16) . "ftyp" . "cr";

        self::assertNull($this->detector->detect($this->file($bytes)));
    }

    public function testReturnsNullForMissingFile(): void
    {
        // Le détecteur détecte ; il ne juge pas. Un fichier absent n'est pas un RAW.
        self::assertNull($this->detector->detect('/chemin/qui/n/existe/pas.cr2'));
    }

    public function testIgnoresExtension(): void
    {
        // La détection se fait sur la signature, jamais sur le nom : un CR2
        // renommé en .jpg reste un CR2.
        $bytes = "II" . pack('v', 42) . pack('V', 16) . "CR\x02\x00" . str_repeat("\x00", 8);
        $path = $this->file($bytes, '.jpg');

        self::assertSame(Format::CR2, $this->detector->detect($path));
    }

    /**
     * Construit un TIFF little-endian minimal : en-tête, un IFD à l'offset 8
     * contenant une seule entrée, puis la fin de chaîne.
     */
    private function tiffWithTag(int $tag, string $inlineValue): string
    {
        $entry = pack('v', $tag)      // tag
            . pack('v', 1)            // type BYTE
            . pack('V', 4)            // count
            . $inlineValue;           // valeur inline (<= 4 octets)

        return "II" . pack('v', 42) . pack('V', 8)
            . pack('v', 1)            // une entrée
            . $entry
            . pack('V', 0);           // fin de chaîne d'IFD
    }

    /**
     * TIFF dont le tag Make (0x010F) pointe vers une chaîne ASCII stockée
     * après l'IFD — la valeur dépasse 4 octets, donc l'offset est indirect.
     */
    private function tiffWithMake(string $make): string
    {
        $value = $make . "\x00";
        $header = "II" . pack('v', 42) . pack('V', 8);

        // en-tête(8) + count(2) + entrée(12) + offset suivant(4) = 26
        $valueOffset = 26;

        $entry = pack('v', 0x010F)              // Make
            . pack('v', 2)                      // type ASCII
            . pack('V', strlen($value))         // count
            . pack('V', $valueOffset);          // offset indirect

        return $header . pack('v', 1) . $entry . pack('V', 0) . $value;
    }

    private function file(string $bytes, string $suffix = '.raw'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rpe') . $suffix;
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }
}
