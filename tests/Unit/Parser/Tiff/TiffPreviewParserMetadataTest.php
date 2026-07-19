<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Tests\Unit\Parser\Tiff;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RonanLenouvel\RawPreviewExtractor\ExtractedPreview;
use RonanLenouvel\RawPreviewExtractor\Format\Format;
use RonanLenouvel\RawPreviewExtractor\Parser\Tiff\TiffPreviewParser;
use RonanLenouvel\RawPreviewExtractor\Parser\Tiff\TiffTag;
use RonanLenouvel\RawPreviewExtractor\RawMetadata;

/**
 * Lecture des réglages de prise de vue (EXIF) en marge de la preview.
 *
 * Les valeurs hors-ligne (chaînes, rationnels) sont posées dans un « tas »
 * après les deux IFD ; chaque entrée porte l'offset absolu vers sa valeur.
 */
#[CoversClass(TiffPreviewParser::class)]
#[CoversClass(RawMetadata::class)]
#[CoversClass(ExtractedPreview::class)]
final class TiffPreviewParserMetadataTest extends TestCase
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

    public function testReadsFullShootingMetadata(): void
    {
        $meta = $this->parser->extract($this->tiffWithMetadata(), Format::CR2)->metadata;

        self::assertInstanceOf(RawMetadata::class, $meta);
        self::assertSame('Canon', $meta->cameraMake);
        self::assertSame('Canon EOS R5', $meta->cameraModel);
        self::assertSame('2024:06:15 12:30:45', $meta->dateTimeOriginal);
        self::assertEqualsWithDelta(2.8, $meta->fNumber, 0.0001);
        self::assertSame('1/250', $meta->exposureTime);
        self::assertSame(400, $meta->iso);
        self::assertEqualsWithDelta(50.0, $meta->focalLength, 0.0001);
        self::assertSame('RF50mm F1.8 STM', $meta->lensModel);
        self::assertFalse($meta->isEmpty());
    }

    public function testMetadataIsEmptyWhenTagsAreAbsent(): void
    {
        // IFD0 présent (donc metadata non-null) mais sans aucun tag EXIF.
        $jpeg = $this->jpeg(32, 24);
        $preview = $this->parser->extract($this->tiffJpegOnly($jpeg), Format::NEF);

        self::assertInstanceOf(RawMetadata::class, $preview->metadata);
        self::assertTrue($preview->metadata->isEmpty());
    }

    public function testExposureAtOrAboveOneSecondIsDecimal(): void
    {
        // 2 s → "2" (pas "1/0" ni "2/1").
        $meta = $this->parser->extract($this->tiffWithExposure(2, 1), Format::ARW)->metadata;

        self::assertSame('2', $meta?->exposureTime);
    }

    // --- builders ---

    private function tiffWithMetadata(): string
    {
        $jpeg = $this->jpeg(64, 48);

        $ifd0Count = 5;
        $exifCount = 6;
        $ifd0Size = 2 + $ifd0Count * 12 + 4;
        $exifSize = 2 + $exifCount * 12 + 4;
        $exifOffset = 8 + $ifd0Size;
        $heapBase = $exifOffset + $exifSize;

        $heap = '';
        $put = function (string $bytes) use (&$heap, $heapBase): int {
            $offset = $heapBase + strlen($heap);
            $heap .= $bytes;

            return $offset;
        };

        $makeOff = $put("Canon\x00");
        $modelOff = $put("Canon EOS R5\x00");
        $fNumberOff = $put(pack('V', 28).pack('V', 10));      // 2.8
        $exposureOff = $put(pack('V', 1).pack('V', 250));     // 1/250
        $dateOff = $put("2024:06:15 12:30:45\x00");
        $focalOff = $put(pack('V', 50).pack('V', 1));         // 50 mm
        $lensOff = $put("RF50mm F1.8 STM\x00");

        $jpegOffset = $heapBase + strlen($heap);

        $ifd0 = $this->ifd([
            [TiffTag::Make->value, 2, 6, pack('V', $makeOff)],
            [TiffTag::Model->value, 2, 13, pack('V', $modelOff)],
            [TiffTag::ExifIfdPointer->value, 4, 1, pack('V', $exifOffset)],
            [TiffTag::JpegInterchangeFormat->value, 4, 1, pack('V', $jpegOffset)],
            [TiffTag::JpegInterchangeFormatLength->value, 4, 1, pack('V', strlen($jpeg))],
        ]);

        $exif = $this->ifd([
            [TiffTag::FNumber->value, 5, 1, pack('V', $fNumberOff)],
            [TiffTag::ExposureTime->value, 5, 1, pack('V', $exposureOff)],
            [TiffTag::IsoSpeedRatings->value, 3, 1, pack('v', 400)."\x00\x00"],
            [TiffTag::DateTimeOriginal->value, 2, 20, pack('V', $dateOff)],
            [TiffTag::FocalLength->value, 5, 1, pack('V', $focalOff)],
            [TiffTag::LensModel->value, 2, 16, pack('V', $lensOff)],
        ]);

        return $this->file('II'.pack('v', 42).pack('V', 8).$ifd0.$exif.$heap.$jpeg);
    }

    private function tiffWithExposure(int $numerator, int $denominator): string
    {
        $jpeg = $this->jpeg(16, 16);

        $ifd0Size = 2 + 3 * 12 + 4;
        $exifSize = 2 + 1 * 12 + 4;
        $exifOffset = 8 + $ifd0Size;
        $heapBase = $exifOffset + $exifSize;

        $exposure = pack('V', $numerator).pack('V', $denominator);
        $jpegOffset = $heapBase + strlen($exposure);

        $ifd0 = $this->ifd([
            [TiffTag::ExifIfdPointer->value, 4, 1, pack('V', $exifOffset)],
            [TiffTag::JpegInterchangeFormat->value, 4, 1, pack('V', $jpegOffset)],
            [TiffTag::JpegInterchangeFormatLength->value, 4, 1, pack('V', strlen($jpeg))],
        ]);

        $exif = $this->ifd([
            [TiffTag::ExposureTime->value, 5, 1, pack('V', $heapBase)],
        ]);

        return $this->file('II'.pack('v', 42).pack('V', 8).$ifd0.$exif.$exposure.$jpeg);
    }

    private function tiffJpegOnly(string $jpeg): string
    {
        $ifd0Size = 2 + 2 * 12 + 4;
        $jpegOffset = 8 + $ifd0Size;

        $ifd0 = $this->ifd([
            [TiffTag::JpegInterchangeFormat->value, 4, 1, pack('V', $jpegOffset)],
            [TiffTag::JpegInterchangeFormatLength->value, 4, 1, pack('V', strlen($jpeg))],
        ]);

        return $this->file('II'.pack('v', 42).pack('V', 8).$ifd0.$jpeg);
    }

    /**
     * Assemble un IFD (little-endian) à partir d'entrées déjà encodées.
     *
     * @param list<array{int, int, int, string}> $entries [tag, type, count, valeur 4 octets]
     */
    private function ifd(array $entries): string
    {
        $body = pack('v', count($entries));

        foreach ($entries as [$tag, $type, $count, $value]) {
            $body .= pack('v', $tag).pack('v', $type).pack('V', $count).$value;
        }

        return $body.pack('V', 0);
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 100, 100, 100));

        ob_start();
        imagejpeg($image, null, 90);
        $data = (string) ob_get_clean();
        imagedestroy($image);

        return $data;
    }

    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tppm');
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }
}
