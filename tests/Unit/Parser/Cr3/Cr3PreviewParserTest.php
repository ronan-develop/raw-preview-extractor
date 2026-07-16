<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Tests\Unit\Parser\Cr3;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;
use RonanLenouvel\RawPreviewExtractor\Exception\PreviewNotFoundException;
use RonanLenouvel\RawPreviewExtractor\Format\Format;
use RonanLenouvel\RawPreviewExtractor\Parser\Cr3\Cr3PreviewParser;

#[CoversClass(Cr3PreviewParser::class)]
final class Cr3PreviewParserTest extends TestCase
{
    /** UUID Canon portant les métadonnées, dont PRVW et THMB. */
    private const CANON_UUID = '85c0b687820f11e08111f4ce462b6a48';

    private Cr3PreviewParser $parser;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->parser = new Cr3PreviewParser();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        $this->tempFiles = [];
    }

    public function testExtractsPreviewFromPrvwBox(): void
    {
        $jpeg = $this->jpeg(160, 120);
        $path = $this->cr3(['PRVW' => $jpeg]);

        $preview = $this->parser->extract($path, Format::CR3);

        self::assertSame($jpeg, $preview->jpegData);
        self::assertSame(Format::CR3, $preview->sourceFormat);
    }

    public function testExtractedJpegHasValidDimensions(): void
    {
        $preview = $this->parser->extract($this->cr3(['PRVW' => $this->jpeg(200, 150)]), Format::CR3);

        self::assertSame([200, 150], [$preview->width, $preview->height]);

        // Validation croisée : GD sait relire ce qu'on a extrait.
        $size = getimagesizefromstring($preview->jpegData);
        self::assertNotFalse($size);
        self::assertSame([200, 150], [$size[0], $size[1]]);
    }

    public function testPrefersPrvwOverThmb(): void
    {
        // THMB est la vignette, PRVW la vraie preview. L'ordre des boîtes dans
        // le fichier ne doit pas décider à notre place.
        $thumb = $this->jpeg(32, 24);
        $preview = $this->jpeg(160, 120);

        $path = $this->cr3(['THMB' => $thumb, 'PRVW' => $preview]);

        self::assertSame($preview, $this->parser->extract($path, Format::CR3)->jpegData);
    }

    public function testFallsBackToThmbWhenPrvwIsAbsent(): void
    {
        // Une vignette vaut mieux que pas de preview du tout.
        $thumb = $this->jpeg(48, 36);

        self::assertSame(
            $thumb,
            $this->parser->extract($this->cr3(['THMB' => $thumb]), Format::CR3)->jpegData,
        );
    }

    public function testToleratesVaryingProprietaryHeaderSize(): void
    {
        // PRVW précède son JPEG d'un en-tête propriétaire dont la taille varie
        // selon les modèles — et n'est pas spécifiée publiquement. On cherche le
        // magic FFD8 plutôt que de coder un décalage en dur.
        $jpeg = $this->jpeg(64, 48);

        foreach ([0, 4, 12, 40] as $headerSize) {
            $path = $this->cr3(['PRVW' => str_repeat("\x7F", $headerSize) . $jpeg]);

            self::assertSame(
                $jpeg,
                $this->parser->extract($path, Format::CR3)->jpegData,
                sprintf('en-tête propriétaire de %d octets', $headerSize),
            );
        }
    }

    public function testThrowsPreviewNotFoundWhenNoPreviewBox(): void
    {
        $this->expectException(PreviewNotFoundException::class);
        $this->expectExceptionMessage('preview');

        // CR3 structurellement valide, mais sans PRVW ni THMB.
        $this->parser->extract($this->cr3([]), Format::CR3);
    }

    public function testThrowsPreviewNotFoundWhenPrvwHasNoJpeg(): void
    {
        $this->expectException(PreviewNotFoundException::class);

        // La boîte existe mais ne contient aucun magic FFD8.
        $this->parser->extract($this->cr3(['PRVW' => str_repeat("\x00", 64)]), Format::CR3);
    }

    public function testThrowsCorruptedOnNonIsoBmffFile(): void
    {
        $this->expectException(CorruptedFileException::class);

        $this->parser->extract($this->file('pas du tout un CR3'), Format::CR3);
    }

    public function testThrowsCorruptedWhenJpegHasNoSofSegment(): void
    {
        $this->expectException(CorruptedFileException::class);
        $this->expectExceptionMessage('SOF');

        // Magic FFD8 présent mais aucun segment SOF : dimensions introuvables.
        $fake = "\xFF\xD8\xFF\xE0" . pack('n', 16) . str_repeat("\x00", 14) . "\xFF\xD9";

        $this->parser->extract($this->cr3(['PRVW' => $fake]), Format::CR3);
    }

    public function testIgnoresPreviewBoxesOutsideCanonUuid(): void
    {
        // Une boîte PRVW hors de l'UUID Canon n'est pas la preview du CR3 :
        // s'y fier reviendrait à faire confiance à n'importe quelle boîte
        // portant le bon nom.
        $this->expectException(PreviewNotFoundException::class);

        $stray = $this->box('PRVW', $this->jpeg(16, 12));
        $bytes = $this->box('ftyp', 'crx isom')
            . $this->box('moov', $this->box('trak', $stray));

        $this->parser->extract($this->file($bytes), Format::CR3);
    }

    /**
     * Un vrai JPEG minuscule, produit par GD.
     *
     * GD est autorisé dans les tests — pour fabriquer comme pour valider — mais
     * jamais dans src/ : l'extraction ne dépend d'aucune extension.
     */
    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 30, 90, 140));

        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    /**
     * Un CR3 minimal : ftyp + moov contenant l'UUID Canon, lui-même portant les
     * boîtes demandées.
     *
     * @param array<string, string> $boxes type de boîte => contenu brut
     */
    private function cr3(array $boxes): string
    {
        $inner = '';

        foreach ($boxes as $type => $payload) {
            $inner .= $this->box($type, $payload);
        }

        $uuid = $this->box('uuid', (string) hex2bin(self::CANON_UUID) . $inner);

        return $this->file(
            $this->box('ftyp', 'crx isom')
            . $this->box('moov', $uuid),
        );
    }

    private function box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)) . $type . $payload;
    }

    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'c3p');
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }
}
