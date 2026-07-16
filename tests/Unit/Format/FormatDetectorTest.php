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
