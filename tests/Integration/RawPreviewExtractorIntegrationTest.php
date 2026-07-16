<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RonanLenouvel\RawPreviewExtractor\Exception\UnsupportedFormatException;
use RonanLenouvel\RawPreviewExtractor\Format\Format;
use RonanLenouvel\RawPreviewExtractor\RawPreviewExtractor;
use RonanLenouvel\RawPreviewExtractor\RawPreviewExtractorInterface;

/**
 * Bout-en-bout : l'API publique, sans aucun double, sur de vrais fichiers.
 *
 * Les tests unitaires vérifient chaque pièce isolément — avec des mocks pour la
 * façade. Ceux-ci vérifient que l'assemblage réel fonctionne : c'est la seule
 * chose que l'utilisateur constate.
 */
final class RawPreviewExtractorIntegrationTest extends TestCase
{
    private RawPreviewExtractorInterface $extractor;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        // createDefault() : exactement ce qu'écrit un utilisateur hors Symfony.
        $this->extractor = RawPreviewExtractor::createDefault();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        $this->tempFiles = [];
    }

    #[DataProvider('provideRawFormats')]
    public function testExtractsPreviewFromEveryFormat(Format $format, string $bytes): void
    {
        $path = $this->file($bytes);

        self::assertTrue($this->extractor->supports($path), 'supports()');

        $preview = $this->extractor->extract($path);

        self::assertSame($format, $preview->sourceFormat);
        self::assertSame([320, 240], [$preview->width, $preview->height]);

        // Validation croisée : GD relit ce que nous avons extrait sans notre
        // aide. C'est un juge indépendant de notre parsing.
        $size = getimagesizefromstring($preview->jpegData);
        self::assertNotFalse($size, 'le JPEG extrait doit être décodable');
        self::assertSame([320, 240], [$size[0], $size[1]]);
        self::assertSame(IMAGETYPE_JPEG, $size[2]);
    }

    /**
     * @return iterable<string, array{Format, string}>
     */
    public static function provideRawFormats(): iterable
    {
        $jpeg = self::jpeg(320, 240);

        yield 'CR2' => [Format::CR2, self::cr2($jpeg)];
        yield 'NEF' => [Format::NEF, self::tiffWithMake('NIKON CORPORATION', $jpeg)];
        yield 'ARW' => [Format::ARW, self::tiffWithMake('SONY', $jpeg)];
        yield 'DNG' => [Format::DNG, self::dng($jpeg)];
        yield 'CR3' => [Format::CR3, self::cr3($jpeg)];
    }

    public function testDoesNotSupportOrdinaryJpeg(): void
    {
        $path = $this->file(self::jpeg(64, 48));

        self::assertFalse($this->extractor->supports($path));
        $this->expectException(UnsupportedFormatException::class);
        $this->extractor->extract($path);
    }

    public function testDegradesGracefullyWithASingleCatch(): void
    {
        // L'usage réel : un seul catch suffit à ne jamais laisser fuir d'erreur.
        $paths = [
            $this->file('pas un RAW du tout'),
            $this->file(self::jpeg(8, 8)),
            '/chemin/qui/nexiste/pas.cr2',
        ];

        foreach ($paths as $path) {
            $thumbnail = null;

            try {
                $thumbnail = $this->extractor->extract($path)->jpegData;
            } catch (\RonanLenouvel\RawPreviewExtractor\Exception\RawPreviewExtractorException) {
                // dégradation : pas de vignette, pas d'erreur fatale
            }

            self::assertNull($thumbnail, $path);
        }
    }

    public function testSupportsIgnoresTheFileExtension(): void
    {
        // La détection est binaire : un CR2 renommé reste un CR2.
        $path = $this->file(self::cr2(self::jpeg(320, 240)), '.jpg');

        self::assertTrue($this->extractor->supports($path));
        self::assertSame(Format::CR2, $this->extractor->extract($path)->sourceFormat);
    }

    /** Un vrai JPEG, produit par GD — autorisé dans les tests, jamais dans src/. */
    private static function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 60, 40));

        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    /** CR2 : en-tête TIFF, signature Canon « CR » aux octets 8-9, puis l'IFD. */
    private static function cr2(string $jpeg): string
    {
        $ifdOffset = 12;
        $jpegOffset = $ifdOffset + 2 + 24 + 4;

        return 'II' . pack('v', 42) . pack('V', $ifdOffset) . "CR\x02\x00"
            . self::jpegIfd($jpegOffset, strlen($jpeg)) . $jpeg;
    }

    /** NEF et ARW se distinguent par le tag Make de l'IFD0. */
    private static function tiffWithMake(string $make, string $jpeg): string
    {
        $make .= "\x00";
        $entries = 3;
        $makeOffset = 8 + 2 + 12 * $entries + 4;
        $jpegOffset = $makeOffset + strlen($make);

        $ifd = pack('v', $entries)
            . pack('v', 0x010F) . pack('v', 2) . pack('V', strlen($make)) . pack('V', $makeOffset)
            . pack('v', 0x0201) . pack('v', 4) . pack('V', 1) . pack('V', $jpegOffset)
            . pack('v', 0x0202) . pack('v', 4) . pack('V', 1) . pack('V', strlen($jpeg))
            . pack('V', 0);

        return 'II' . pack('v', 42) . pack('V', 8) . $ifd . $make . $jpeg;
    }

    /** DNG : le tag DNGVersion suffit à l'identifier. */
    private static function dng(string $jpeg): string
    {
        $entries = 3;
        $jpegOffset = 8 + 2 + 12 * $entries + 4;

        $ifd = pack('v', $entries)
            . pack('v', 0x0201) . pack('v', 4) . pack('V', 1) . pack('V', $jpegOffset)
            . pack('v', 0x0202) . pack('v', 4) . pack('V', 1) . pack('V', strlen($jpeg))
            . pack('v', 0xC612) . pack('v', 1) . pack('V', 4) . "\x01\x04\x00\x00"
            . pack('V', 0);

        return 'II' . pack('v', 42) . pack('V', 8) . $ifd . $jpeg;
    }

    /**
     * CR3 reproduisant la structure **réelle**, vérifiée sur EOS R et EOS RP :
     * PRVW vit dans sa propre boîte uuid à la racine, précédé de 8 octets
     * propriétaires — pas sous l'UUID Canon, qui ne porte que THMB.
     */
    private static function cr3(string $jpeg): string
    {
        $canonUuid = (string) hex2bin('85c0b687820f11e08111f4ce462b6a48');
        $previewUuid = (string) hex2bin('eaf42b5e1c984b88b9fbb7dc406e4d16');

        // En-tête propriétaire de PRVW avant le JPEG : taille variable selon les modèles.
        $prvw = self::box('PRVW', str_repeat("\x00", 12) . $jpeg);

        return self::box('ftyp', 'crx isom')
            . self::box('moov', self::box('uuid', $canonUuid . self::box('CMT1', 'x')))
            // 8 octets propriétaires entre l'UUID et la première boîte.
            . self::box('uuid', $previewUuid . str_repeat("\x00", 8) . $prvw)
            . self::box('mdat', str_repeat("\x00", 16));
    }

    private static function jpegIfd(int $jpegOffset, int $jpegLength): string
    {
        return pack('v', 2)
            . pack('v', 0x0201) . pack('v', 4) . pack('V', 1) . pack('V', $jpegOffset)
            . pack('v', 0x0202) . pack('v', 4) . pack('V', 1) . pack('V', $jpegLength)
            . pack('V', 0);
    }

    private static function box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)) . $type . $payload;
    }

    private function file(string $bytes, string $suffix = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rpe') . $suffix;
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }
}
