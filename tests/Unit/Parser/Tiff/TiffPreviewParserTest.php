<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Tests\Unit\Parser\Tiff;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RonanLenouvel\RawPreviewExtractor\ExtractedPreview;
use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;
use RonanLenouvel\RawPreviewExtractor\Exception\PreviewNotFoundException;
use RonanLenouvel\RawPreviewExtractor\Format\Format;
use RonanLenouvel\RawPreviewExtractor\Parser\Tiff\TiffPreviewParser;
use RonanLenouvel\RawPreviewExtractor\Parser\Tiff\TiffTag;

#[CoversClass(TiffPreviewParser::class)]
#[CoversClass(ExtractedPreview::class)]
final class TiffPreviewParserTest extends TestCase
{
    private TiffPreviewParser $parser;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->parser = new TiffPreviewParser();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        $this->tempFiles = [];
    }

    public function testExtractsPreviewViaJpegInterchangeFormat(): void
    {
        $jpeg = $this->jpeg(64, 48);
        $path = $this->tiffWithJpeg($jpeg);

        $preview = $this->parser->extract($path, Format::CR2);

        self::assertSame($jpeg, $preview->jpegData);
        self::assertSame(Format::CR2, $preview->sourceFormat);
    }

    public function testExtractedJpegHasValidDimensions(): void
    {
        // Validation croisée : si l'extraction est correcte, GD sait relire le
        // résultat. Plus fort qu'un simple contrôle du magic FFD8.
        $preview = $this->parser->extract($this->tiffWithJpeg($this->jpeg(120, 90)), Format::NEF);

        self::assertSame(120, $preview->width);
        self::assertSame(90, $preview->height);

        $size = getimagesizefromstring($preview->jpegData);
        self::assertNotFalse($size);
        self::assertSame([120, 90], [$size[0], $size[1]]);
    }

    public function testChoosesLargestPreviewAmongIfds(): void
    {
        // Un RAW porte plusieurs previews : vignette en IFD0, image moyenne en
        // IFD1, pleine résolution en IFD2. On veut la plus grande, sans coder en
        // dur l'organisation d'un constructeur donné.
        $small = $this->jpeg(16, 12);
        $large = $this->jpeg(160, 120);

        $path = $this->tiffWithChain([$small, $large]);

        self::assertSame($large, $this->parser->extract($path, Format::CR2)->jpegData);
    }

    public function testExtractsPreviewViaStripOffsets(): void
    {
        // Certains constructeurs n'utilisent pas JPEGInterchangeFormat mais
        // StripOffsets/StripByteCounts avec Compression = 6 (JPEG).
        $jpeg = $this->jpeg(32, 24);

        $entries = [
            [TiffTag::Compression->value, 3, 1, pack('v', 6) . "\x00\x00"],
            [TiffTag::StripOffsets->value, 4, 1, 'OFFSET'],
            [TiffTag::StripByteCounts->value, 4, 1, pack('V', strlen($jpeg))],
        ];

        self::assertSame(
            $jpeg,
            $this->parser->extract($this->tiffWith($entries, $jpeg), Format::ARW)->jpegData,
        );
    }

    public function testIgnoresStripOffsetsWhenNotJpegCompressed(): void
    {
        // Compression = 1 : données brutes du capteur, pas un JPEG. On ne doit
        // pas les prendre pour une preview.
        $entries = [
            [TiffTag::Compression->value, 3, 1, pack('v', 1) . "\x00\x00"],
            [TiffTag::StripOffsets->value, 4, 1, 'OFFSET'],
            [TiffTag::StripByteCounts->value, 4, 1, pack('V', 100)],
        ];

        $this->expectException(PreviewNotFoundException::class);

        $this->parser->extract($this->tiffWith($entries, str_repeat("\x00", 100)), Format::CR2);
    }

    public function testFindsPreviewInSubIfd(): void
    {
        // Cas typique du NEF : la grande preview vit dans un sous-IFD référencé
        // par le tag SubIFDs, pas dans la chaîne principale.
        $jpeg = $this->jpeg(80, 60);
        $path = $this->tiffWithSubIfd($jpeg);

        self::assertSame($jpeg, $this->parser->extract($path, Format::NEF)->jpegData);
    }

    public function testThrowsPreviewNotFoundWhenJpegHasNoSofSegment(): void
    {
        // Un bloc qui commence par FFD8 mais ne porte aucun SOF n'est pas une
        // preview exploitable — sans que le fichier soit corrompu pour autant.
        // C'est un candidat qu'on écarte, pas une structure qui ment.
        $this->expectException(PreviewNotFoundException::class);

        $fake = "\xFF\xD8" . "\xFF\xE0" . pack('n', 16) . str_repeat("\x00", 14) . "\xFF\xD9";

        $entries = [
            [TiffTag::JpegInterchangeFormat->value, 4, 1, 'OFFSET'],
            [TiffTag::JpegInterchangeFormatLength->value, 4, 1, pack('V', strlen($fake))],
        ];

        $this->parser->extract($this->tiffWith($entries, $fake), Format::CR2);
    }

    public function testStopsDescendingBeyondMaxSubIfdDepth(): void
    {
        // Un fichier hostile peut chaîner des SubIFDs à l'infini. On s'arrête,
        // donc la preview enfouie trop profond reste introuvable.
        $this->expectException(PreviewNotFoundException::class);

        $this->parser->extract($this->tiffWithDeepSubIfds($this->jpeg(16, 12), 8), Format::NEF);
    }

    public function testIgnoresLosslessJpegSensorData(): void
    {
        // Canon stocke les données du capteur d'un CR2 en JPEG *lossless*, dans
        // un IFD qui déclare honnêtement Compression = 6. Ce bloc est le plus
        // gros du fichier : sans contrôle du marqueur SOF, la stratégie « la
        // plus grande preview » le choisit — et rend 28 Mo indécodables.
        $sensor = $this->jpegWithSof("\xC3", 6880, 4544);   // SOF3 = lossless
        $preview = $this->jpeg(64, 48);                     // SOF0 = baseline

        $path = $this->tiffWithChain([$preview, $sensor]);

        // La preview baseline gagne, bien qu'elle soit la plus petite.
        self::assertSame($preview, $this->parser->extract($path, Format::CR2)->jpegData);
    }

    public function testThrowsPreviewNotFoundWhenOnlyLosslessJpegExists(): void
    {
        // Un fichier dont le seul bloc « JPEG » est du lossless n'a pas de
        // preview exploitable — mieux vaut le dire que rendre l'indécodable.
        $this->expectException(PreviewNotFoundException::class);

        $sensor = $this->jpegWithSof("\xC3", 100, 100);

        $this->parser->extract($this->tiffWithJpeg($sensor), Format::CR2);
    }

    public function testAcceptsProgressiveJpeg(): void
    {
        // SOF2 (progressif) est parfaitement décodable : ne pas le rejeter avec
        // le lossless.
        $progressive = $this->jpegWithSof("\xC2", 320, 240);

        $preview = $this->parser->extract($this->tiffWithJpeg($progressive), Format::DNG);

        self::assertSame([320, 240], [$preview->width, $preview->height]);
    }

    public function testThrowsPreviewNotFoundWhenNoJpegTag(): void
    {
        $this->expectException(PreviewNotFoundException::class);
        $this->expectExceptionMessage('preview');

        // TIFF valide, mais aucun tag ne désigne de JPEG.
        $entries = [[TiffTag::ImageWidth->value, 3, 1, pack('v', 1600) . "\x00\x00"]];

        $this->parser->extract($this->tiffWith($entries, ''), Format::DNG);
    }

    public function testThrowsCorruptedWhenJpegOffsetIsOutOfBounds(): void
    {
        // Le tag annonce un JPEG hors du fichier : c'est un fichier corrompu,
        // pas un fichier sans preview. La distinction est contractuelle.
        $this->expectException(CorruptedFileException::class);

        $entries = [
            [TiffTag::JpegInterchangeFormat->value, 4, 1, pack('V', 99999)],
            [TiffTag::JpegInterchangeFormatLength->value, 4, 1, pack('V', 500)],
        ];

        $this->parser->extract($this->tiffWith($entries, ''), Format::CR2);
    }

    public function testThrowsCorruptedWhenBlockIsNotJpeg(): void
    {
        // Le tag pointe vers des données qui ne commencent pas par FFD8 :
        // la structure ment, le fichier est corrompu.
        $this->expectException(CorruptedFileException::class);
        $this->expectExceptionMessage('FFD8');

        $notJpeg = str_repeat("\x00", 40);

        $entries = [
            [TiffTag::JpegInterchangeFormat->value, 4, 1, 'OFFSET'],
            [TiffTag::JpegInterchangeFormatLength->value, 4, 1, pack('V', strlen($notJpeg))],
        ];

        $this->parser->extract($this->tiffWith($entries, $notJpeg), Format::CR2);
    }

    public function testThrowsPreviewNotFoundWhenLengthIsZero(): void
    {
        $this->expectException(PreviewNotFoundException::class);

        $entries = [
            [TiffTag::JpegInterchangeFormat->value, 4, 1, pack('V', 26)],
            [TiffTag::JpegInterchangeFormatLength->value, 4, 1, pack('V', 0)],
        ];

        $this->parser->extract($this->tiffWith($entries, 'x'), Format::CR2);
    }

    public function testThrowsCorruptedOnNonTiffFile(): void
    {
        $this->expectException(CorruptedFileException::class);

        $this->parser->extract($this->file("\xFF\xD8\xFF\xE0not a tiff"), Format::CR2);
    }

    public function testDimensionsComeFromTheJpegItself(): void
    {
        // Les tags ImageWidth/ImageLength de l'IFD décrivent parfois l'image RAW
        // et non la preview : mentir de 4000 px ne doit pas contaminer la sortie.
        $jpeg = $this->jpeg(48, 36);

        $entries = [
            [TiffTag::ImageWidth->value, 3, 1, pack('v', 4000) . "\x00\x00"],
            [TiffTag::ImageLength->value, 3, 1, pack('v', 3000) . "\x00\x00"],
            [TiffTag::JpegInterchangeFormat->value, 4, 1, 'OFFSET'],
            [TiffTag::JpegInterchangeFormatLength->value, 4, 1, pack('V', strlen($jpeg))],
        ];

        $preview = $this->parser->extract($this->tiffWith($entries, $jpeg), Format::CR2);

        self::assertSame([48, 36], [$preview->width, $preview->height]);
    }

    /**
     * Un vrai JPEG minuscule, produit par GD.
     *
     * GD est autorisé dans les tests — pour fabriquer et pour valider — mais
     * jamais dans src/ : l'extraction ne doit dépendre d'aucune extension.
     */
    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 120, 90, 60));

        ob_start();
        imagejpeg($image, null, 90);
        $data = (string) ob_get_clean();
        imagedestroy($image);

        return $data;
    }

    /**
     * Un JPEG minimal portant le marqueur SOF demandé.
     *
     * GD ne sait produire que du baseline (SOF0) : pour tester le rejet du
     * lossless (SOF3), il faut forger la structure à la main.
     */
    private function jpegWithSof(string $marker, int $width, int $height): string
    {
        // SOI, APP0/JFIF, puis le segment SOF : longueur, précision, hauteur,
        // largeur, nombre de composantes.
        $sof = "\xFF" . $marker . pack('n', 11) . "\x08"
            . pack('n', $height) . pack('n', $width) . "\x01\x01\x11\x00";

        return "\xFF\xD8"
            . "\xFF\xE0" . pack('n', 16) . "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            . $sof
            . "\xFF\xD9";
    }

    /**
     * TIFF minimal dont l'IFD0 désigne le JPEG via JPEGInterchangeFormat.
     */
    private function tiffWithJpeg(string $jpeg): string
    {
        return $this->tiffWith([
            [TiffTag::JpegInterchangeFormat->value, 4, 1, 'OFFSET'],
            [TiffTag::JpegInterchangeFormatLength->value, 4, 1, pack('V', strlen($jpeg))],
        ], $jpeg);
    }

    /**
     * Construit un TIFF à un seul IFD, suivi d'une charge utile.
     *
     * La valeur littérale « OFFSET » dans une entrée est remplacée par l'offset
     * réel de la charge utile — calculé une fois la taille de l'IFD connue.
     *
     * @param list<array{int, int, int, string}> $entries
     */
    private function tiffWith(array $entries, string $payload): string
    {
        $payloadOffset = 8 + 2 + 12 * count($entries) + 4;
        $body = pack('v', count($entries));

        foreach ($entries as [$tag, $type, $count, $value]) {
            if ('OFFSET' === $value) {
                $value = pack('V', $payloadOffset);
            }

            $body .= pack('v', $tag) . pack('v', $type) . pack('V', $count) . $value;
        }

        $body .= pack('V', 0);

        return $this->file('II' . pack('v', 42) . pack('V', 8) . $body . $payload);
    }

    /**
     * TIFF à plusieurs IFD chaînés, un JPEG par IFD.
     *
     * @param list<string> $jpegs
     */
    private function tiffWithChain(array $jpegs): string
    {
        $ifdSize = 2 + 2 * 12 + 4;
        $count = count($jpegs);
        $body = '';
        $payload = '';
        $payloadOffset = 8 + $ifdSize * $count;

        foreach ($jpegs as $i => $jpeg) {
            $next = $i === $count - 1 ? 0 : 8 + $ifdSize * ($i + 1);

            $body .= pack('v', 2)
                . pack('v', TiffTag::JpegInterchangeFormat->value) . pack('v', 4)
                . pack('V', 1) . pack('V', $payloadOffset + strlen($payload))
                . pack('v', TiffTag::JpegInterchangeFormatLength->value) . pack('v', 4)
                . pack('V', 1) . pack('V', strlen($jpeg))
                . pack('V', $next);

            $payload .= $jpeg;
        }

        return $this->file('II' . pack('v', 42) . pack('V', 8) . $body . $payload);
    }

    /**
     * TIFF dont les sous-IFD s'enchaînent sur `$depth` niveaux, la preview
     * n'étant qu'au tout dernier.
     */
    private function tiffWithDeepSubIfds(string $jpeg, int $depth): string
    {
        $linkSize = 2 + 12 + 4;      // un IFD à une seule entrée SubIFDs
        $leafSize = 2 + 2 * 12 + 4;  // l'IFD feuille, avec les deux tags JPEG
        $jpegOffset = 8 + $linkSize * $depth + $leafSize;

        $body = '';

        for ($i = 0; $i < $depth; ++$i) {
            $body .= pack('v', 1)
                . pack('v', TiffTag::SubIfds->value) . pack('v', 4)
                . pack('V', 1) . pack('V', 8 + $linkSize * ($i + 1))
                . pack('V', 0);
        }

        $body .= pack('v', 2)
            . pack('v', TiffTag::JpegInterchangeFormat->value) . pack('v', 4)
            . pack('V', 1) . pack('V', $jpegOffset)
            . pack('v', TiffTag::JpegInterchangeFormatLength->value) . pack('v', 4)
            . pack('V', 1) . pack('V', strlen($jpeg))
            . pack('V', 0);

        return $this->file('II' . pack('v', 42) . pack('V', 8) . $body . $jpeg);
    }

    /**
     * TIFF dont l'IFD0 référence un sous-IFD qui porte la preview.
     */
    private function tiffWithSubIfd(string $jpeg): string
    {
        $ifd0Size = 2 + 12 + 4;
        $subIfdOffset = 8 + $ifd0Size;
        $subIfdSize = 2 + 2 * 12 + 4;
        $jpegOffset = $subIfdOffset + $subIfdSize;

        $ifd0 = pack('v', 1)
            . pack('v', TiffTag::SubIfds->value) . pack('v', 4)
            . pack('V', 1) . pack('V', $subIfdOffset)
            . pack('V', 0);

        $subIfd = pack('v', 2)
            . pack('v', TiffTag::JpegInterchangeFormat->value) . pack('v', 4)
            . pack('V', 1) . pack('V', $jpegOffset)
            . pack('v', TiffTag::JpegInterchangeFormatLength->value) . pack('v', 4)
            . pack('V', 1) . pack('V', strlen($jpeg))
            . pack('V', 0);

        return $this->file('II' . pack('v', 42) . pack('V', 8) . $ifd0 . $subIfd . $jpeg);
    }

    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpp');
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }
}
