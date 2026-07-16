<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Tests\Unit\Parser\Tiff;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;
use RonanLenouvel\RawPreviewExtractor\Parser\Tiff\IfdEntry;
use RonanLenouvel\RawPreviewExtractor\Parser\Tiff\TiffReader;
use RonanLenouvel\RawPreviewExtractor\Parser\Tiff\TiffTag;

#[CoversClass(TiffReader::class)]
#[CoversClass(IfdEntry::class)]
final class TiffReaderTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        $this->tempFiles = [];
    }

    public function testReadsLittleEndianHeader(): void
    {
        $reader = $this->reader($this->tiff('II', [[TiffTag::ImageWidth->value, 3, 1, "\x40\x06\x00\x00"]]));

        self::assertFalse($reader->isBigEndian());
    }

    public function testReadsBigEndianHeader(): void
    {
        $reader = $this->reader($this->tiff('MM', [[TiffTag::ImageWidth->value, 3, 1, "\x06\x40\x00\x00"]]));

        self::assertTrue($reader->isBigEndian());
    }

    public function testThrowsOnUnknownByteOrder(): void
    {
        $this->expectException(CorruptedFileException::class);
        $this->expectExceptionMessage('Ordre d\'octets');

        $this->reader("XX\x2a\x00" . pack('V', 8) . str_repeat("\x00", 8));
    }

    public function testThrowsOnWrongMagicNumber(): void
    {
        $this->expectException(CorruptedFileException::class);
        $this->expectExceptionMessage('Nombre magique');

        // 43 au lieu de 42 : l'en-tête a la bonne forme mais n'est pas du TIFF.
        $this->reader('II' . pack('v', 43) . pack('V', 8) . str_repeat("\x00", 8));
    }

    public function testThrowsOnTruncatedFile(): void
    {
        $this->expectException(CorruptedFileException::class);

        // Moins que les 8 octets de l'en-tête.
        $this->reader('II' . pack('v', 42));
    }

    public function testThrowsOnMissingFile(): void
    {
        $this->expectException(CorruptedFileException::class);
        $this->expectExceptionMessage('illisible');

        new TiffReader('/chemin/inexistant.cr2');
    }

    public function testParsesIfdEntries(): void
    {
        $reader = $this->reader($this->tiff('II', [
            [TiffTag::ImageWidth->value, 3, 1, "\x40\x06\x00\x00"],   // SHORT 1600
            [TiffTag::ImageLength->value, 3, 1, "\xB0\x04\x00\x00"],  // SHORT 1200
        ]));

        $entries = $reader->readIfd(8);

        self::assertCount(2, $entries);
        self::assertSame(TiffTag::ImageWidth->value, $entries[0]->tag);
        self::assertSame(3, $entries[0]->type);
        self::assertSame(1, $entries[0]->count);
    }

    public function testReadsInlineShortValue(): void
    {
        // Règle des 4 octets : 2 × 1 = 2 <= 4, la valeur est DANS l'entrée.
        $reader = $this->reader($this->tiff('II', [
            [TiffTag::ImageWidth->value, 3, 1, "\x40\x06\x00\x00"],
        ]));

        self::assertSame(1600, $reader->readIfd(8)[0]->value());
    }

    public function testReadsInlineLongValue(): void
    {
        // 4 × 1 = 4 <= 4 : encore inline, mais sur toute la largeur du champ.
        $reader = $this->reader($this->tiff('II', [
            [TiffTag::JpegInterchangeFormat->value, 4, 1, pack('V', 123456)],
        ]));

        self::assertSame(123456, $reader->readIfd(8)[0]->value());
    }

    public function testReadsInlineValueBigEndian(): void
    {
        // L'endianness gouverne aussi la lecture des valeurs inline.
        $reader = $this->reader($this->tiff('MM', [
            [TiffTag::ImageWidth->value, 3, 1, "\x06\x40\x00\x00"],
        ]));

        self::assertSame(1600, $reader->readIfd(8)[0]->value());
    }

    public function testReadsIndirectValueBeyondFourBytes(): void
    {
        // 8 octets de données > 4 : le champ contient un OFFSET, pas la valeur.
        // Se tromper ici donne des offsets absurdes qui ressemblent à des données.
        $payload = pack('V', 111) . pack('V', 222);
        $offset = 8 + 2 + 12 + 4;

        $bytes = $this->tiff('II', [
            [TiffTag::SubIfds->value, 4, 2, pack('V', $offset)],
        ]) . $payload;

        self::assertSame([111, 222], $this->reader($bytes)->readIfd(8)[0]->values);
    }

    public function testFollowsIfdChain(): void
    {
        // IFD0 → IFD1 → 0. Chaque IFD décrit une image : dans un RAW, typiquement
        // la vignette, la preview, puis les données brutes.
        $ifd0 = pack('v', 1)
            . $this->entry(TiffTag::ImageWidth->value, 3, 1, "\x40\x06\x00\x00")
            . pack('V', 8 + 18);

        $ifd1 = pack('v', 1)
            . $this->entry(TiffTag::ImageWidth->value, 3, 1, "\x80\x02\x00\x00")
            . pack('V', 0);

        $reader = $this->reader('II' . pack('v', 42) . pack('V', 8) . $ifd0 . $ifd1);

        self::assertSame([8, 26], $reader->readIfdOffsets());
    }

    public function testDetectsIfdChainLoop(): void
    {
        // Un IFD qui pointe sur lui-même : sans garde-fou, boucle infinie.
        // Aucun fichier réel ne fait ça — un fichier hostile, si.
        $ifd = pack('v', 1)
            . $this->entry(TiffTag::ImageWidth->value, 3, 1, "\x40\x06\x00\x00")
            . pack('V', 8);   // ← pointe sur lui-même

        $reader = $this->reader('II' . pack('v', 42) . pack('V', 8) . $ifd);

        // On ne lève pas : on s'arrête. Un IFD déjà visité clôt la chaîne.
        self::assertSame([8], $reader->readIfdOffsets());
    }

    public function testReadsByteTypeValues(): void
    {
        // Type BYTE : 4 × 1 = 4 octets, inline. C'est la forme de DNGVersion.
        $reader = $this->reader($this->tiff('II', [
            [TiffTag::DngVersion->value, 1, 4, "\x01\x04\x00\x00"],
        ]));

        self::assertSame([1, 4, 0, 0], $reader->readIfd(8)[0]->values);
    }

    public function testReadsRationalTypeValue(): void
    {
        // Type RATIONAL : 8 octets par valeur (numérateur, dénominateur), donc
        // toujours indirect. Seul le numérateur nous intéresse.
        $payload = pack('V', 300) . pack('V', 1);
        $offset = 8 + 2 + 12 + 4;

        $bytes = $this->tiff('II', [
            [TiffTag::ImageWidth->value, 5, 1, pack('V', $offset)],
        ]) . $payload;

        self::assertSame(300, $this->reader($bytes)->readIfd(8)[0]->value());
    }

    public function testStopsFollowingChainAfterTooManyIfds(): void
    {
        // Une chaîne de 200 IFD : aucun RAW réel n'en a plus d'une poignée.
        // Sans plafond, un fichier hostile ferait parcourir une chaîne sans fin.
        $ifdSize = 2 + 12 + 4;
        $bytes = 'II' . pack('v', 42) . pack('V', 8);

        for ($i = 0; $i < 200; ++$i) {
            $next = 8 + ($i + 1) * $ifdSize;
            $bytes .= pack('v', 1)
                . $this->entry(TiffTag::ImageWidth->value, 3, 1, "\x40\x06\x00\x00")
                . pack('V', 199 === $i ? 0 : $next);
        }

        // On s'arrête au plafond au lieu de parcourir les 200.
        self::assertCount(64, $this->reader($bytes)->readIfdOffsets());
    }

    public function testThrowsWhenValueLengthIsAbsurd(): void
    {
        $this->expectException(CorruptedFileException::class);
        $this->expectExceptionMessage('absurde');

        // count = 100 000 × 4 octets (LONG) = 400 Ko annoncés dans un fichier
        // de 26 octets : refuser avant d'allouer.
        $this->reader($this->tiff('II', [
            [TiffTag::SubIfds->value, 4, 100000, pack('V', 26)],
        ]))->readIfd(8);
    }

    public function testThrowsWhenIfdOffsetIsOutOfBounds(): void
    {
        $this->expectException(CorruptedFileException::class);
        $this->expectExceptionMessage('hors bornes');

        $this->reader('II' . pack('v', 42) . pack('V', 9999) . str_repeat("\x00", 8))
            ->readIfd(9999);
    }

    public function testThrowsWhenIfdOverlapsHeader(): void
    {
        $this->expectException(CorruptedFileException::class);

        // Un IFD ne peut pas commencer avant l'octet 8 : il chevaucherait l'en-tête.
        $this->reader('II' . pack('v', 42) . pack('V', 8) . str_repeat("\x00", 8))
            ->readIfd(4);
    }

    public function testThrowsWhenIfdIsTruncatedMidEntry(): void
    {
        $this->expectException(CorruptedFileException::class);
        $this->expectExceptionMessage('tronqué');

        // Annonce 3 entrées, le fichier s'arrête au milieu de la première.
        $this->reader('II' . pack('v', 42) . pack('V', 8) . pack('v', 3) . "\x0F\x01\x02")
            ->readIfd(8);
    }

    public function testThrowsWhenEntryCountIsAbsurd(): void
    {
        $this->expectException(CorruptedFileException::class);

        // 65535 entrées = 786 420 octets annoncés dans un fichier de 14.
        $this->reader('II' . pack('v', 42) . pack('V', 8) . pack('v', 65535) . pack('V', 0))
            ->readIfd(8);
    }

    public function testThrowsWhenIndirectValueOffsetIsOutOfBounds(): void
    {
        $this->expectException(CorruptedFileException::class);

        $reader = $this->reader($this->tiff('II', [
            [TiffTag::SubIfds->value, 4, 4, pack('V', 9999)],
        ]));

        $reader->readIfd(8)[0]->values;
    }

    public function testThrowsWhenValueCountWouldOverflow(): void
    {
        $this->expectException(CorruptedFileException::class);

        // count énorme × 8 octets (RATIONAL) : refuser avant d'allouer.
        $reader = $this->reader($this->tiff('II', [
            [TiffTag::ImageWidth->value, 5, 0xFFFFFF, pack('V', 26)],
        ]));

        $reader->readIfd(8)[0]->values;
    }

    public function testEmptyIfdYieldsNoEntries(): void
    {
        $reader = $this->reader('II' . pack('v', 42) . pack('V', 8) . pack('v', 0) . pack('V', 0));

        self::assertSame([], $reader->readIfd(8));
    }

    public function testIgnoresUnknownDataType(): void
    {
        // Un type inconnu n'est pas une corruption : l'entrée est lisible,
        // seule sa valeur est indéterminable.
        $reader = $this->reader($this->tiff('II', [
            [TiffTag::ImageWidth->value, 99, 1, "\x00\x00\x00\x00"],
        ]));

        self::assertNull($reader->readIfd(8)[0]->value());
    }

    public function testReadsAsciiValue(): void
    {
        $value = "NIKON\x00";
        $offset = 8 + 2 + 12 + 4;

        $bytes = $this->tiff('II', [
            [TiffTag::Make->value, 2, strlen($value), pack('V', $offset)],
        ]) . $value;

        $reader = $this->reader($bytes);

        self::assertSame('NIKON', $reader->readIfd(8)[0]->ascii);
    }

    public function testReadsRawBytesAtOffset(): void
    {
        $bytes = $this->tiff('II', []) . 'CHARGE UTILE';

        self::assertSame('CHARGE', $this->reader($bytes)->readBytes(14, 6));
    }

    public function testThrowsWhenReadingBytesOutOfBounds(): void
    {
        $this->expectException(CorruptedFileException::class);

        $this->reader($this->tiff('II', []))->readBytes(9999, 10);
    }

    public function testThrowsWhenReadingZeroBytes(): void
    {
        $this->expectException(CorruptedFileException::class);

        $this->reader($this->tiff('II', []))->readBytes(8, 0);
    }

    /**
     * Construit un TIFF minimal : en-tête, un IFD à l'offset 8, fin de chaîne.
     *
     * @param list<array{int, int, int, string}> $entries tag, type, count, valeur/offset
     */
    private function tiff(string $byteOrder, array $entries): string
    {
        $body = pack('II' === $byteOrder ? 'v' : 'n', count($entries));

        foreach ($entries as [$tag, $type, $count, $value]) {
            $body .= $this->entry($tag, $type, $count, $value, $byteOrder);
        }

        $body .= pack('II' === $byteOrder ? 'V' : 'N', 0);

        return $byteOrder
            . pack('II' === $byteOrder ? 'v' : 'n', 42)
            . pack('II' === $byteOrder ? 'V' : 'N', 8)
            . $body;
    }

    private function entry(int $tag, int $type, int $count, string $value, string $byteOrder = 'II'): string
    {
        [$short, $long] = 'II' === $byteOrder ? ['v', 'V'] : ['n', 'N'];

        return pack($short, $tag) . pack($short, $type) . pack($long, $count) . $value;
    }

    private function reader(string $bytes): TiffReader
    {
        $path = tempnam(sys_get_temp_dir(), 'tiff');
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return new TiffReader($path);
    }
}
