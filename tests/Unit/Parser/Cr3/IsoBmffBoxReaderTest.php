<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Tests\Unit\Parser\Cr3;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;
use RonanLenouvel\RawPreviewExtractor\Parser\Cr3\Box;
use RonanLenouvel\RawPreviewExtractor\Parser\Cr3\IsoBmffBoxReader;

#[CoversClass(IsoBmffBoxReader::class)]
#[CoversClass(Box::class)]
final class IsoBmffBoxReaderTest extends TestCase
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

    public function testParsesTopLevelBoxes(): void
    {
        $bytes = $this->box('ftyp', 'crx isom')
            . $this->box('moov', str_repeat("\x00", 16))
            . $this->box('mdat', 'DONNEES');

        $boxes = $this->reader($bytes)->readBoxes();

        self::assertSame(['ftyp', 'moov', 'mdat'], array_map(
            static fn (Box $b): string => $b->type,
            $boxes,
        ));
    }

    public function testBoxCarriesOffsetAndLength(): void
    {
        $boxes = $this->reader($this->box('ftyp', 'crx isom'))->readBoxes();

        // Le payload commence après les 8 octets d'en-tête.
        self::assertSame(8, $boxes[0]->payloadOffset);
        self::assertSame(8, $boxes[0]->payloadLength);
    }

    public function testFindsNestedBox(): void
    {
        // moov contient trak, qui contient la cible.
        $inner = $this->box('PRVW', 'CIBLE');
        $trak = $this->box('trak', $inner);
        $bytes = $this->box('ftyp', 'crx ') . $this->box('moov', $trak);

        $found = $this->reader($bytes)->find('PRVW');

        self::assertNotNull($found);
        self::assertSame('PRVW', $found->type);
    }

    public function testFindReturnsNullWhenBoxIsAbsent(): void
    {
        $bytes = $this->box('ftyp', 'crx ') . $this->box('moov', $this->box('trak', 'x'));

        self::assertNull($this->reader($bytes)->find('PRVW'));
    }

    public function testFindsFirstMatchingBox(): void
    {
        $bytes = $this->box('moov', $this->box('THMB', 'PREMIER') . $this->box('THMB', 'SECOND'));

        self::assertSame('PREMIER', $this->reader($bytes)->readPayload(
            $this->reader($bytes)->find('THMB'),
        ));
    }

    public function testHandlesSizeZeroExtendsToEndOfFile(): void
    {
        // size == 0 : la boîte court jusqu'à la fin du fichier. Traiter 0 comme
        // une taille littérale ferait boucler sans fin.
        $payload = 'JUSQUA LA FIN';
        $bytes = $this->box('ftyp', 'crx ') . pack('N', 0) . 'mdat' . $payload;

        $boxes = $this->reader($bytes)->readBoxes();

        self::assertCount(2, $boxes);
        self::assertSame('mdat', $boxes[1]->type);
        self::assertSame(strlen($payload), $boxes[1]->payloadLength);
    }

    public function testHandlesSize64Bit(): void
    {
        // size == 1 : la taille réelle est sur 64 bits après le type, et
        // l'en-tête fait alors 16 octets au lieu de 8.
        $payload = 'GRANDE BOITE';
        $bytes = pack('N', 1) . 'mdat' . pack('J', 16 + strlen($payload)) . $payload;

        $boxes = $this->reader($bytes)->readBoxes();

        self::assertSame('mdat', $boxes[0]->type);
        self::assertSame(16, $boxes[0]->payloadOffset);
        self::assertSame(strlen($payload), $boxes[0]->payloadLength);
    }

    public function testThrowsOnSizeSmallerThanHeader(): void
    {
        $this->expectException(CorruptedFileException::class);
        $this->expectExceptionMessage('Invalid box size');

        // size = 4 : plus petit que les 8 octets d'en-tête. Sans garde, l'offset
        // reculerait et la lecture partirait en boucle.
        $this->reader(pack('N', 4) . 'mdat' . str_repeat("\x00", 16))->readBoxes();
    }

    public function testThrowsOnSize64BitSmallerThanHeader(): void
    {
        $this->expectException(CorruptedFileException::class);

        // size == 1 mais la taille 64 bits annoncée est plus petite que
        // l'en-tête étendu de 16 octets.
        $this->reader(pack('N', 1) . 'mdat' . pack('J', 12) . str_repeat("\x00", 16))
            ->readBoxes();
    }

    public function testThrowsOnSizeBeyondFile(): void
    {
        $this->expectException(CorruptedFileException::class);
        $this->expectExceptionMessage('out of bounds');

        // La boîte annonce 9999 octets dans un fichier qui en fait 16.
        $this->reader(pack('N', 9999) . 'mdat' . str_repeat("\x00", 8))->readBoxes();
    }

    public function testThrowsOnTruncatedFile(): void
    {
        $this->expectException(CorruptedFileException::class);

        // Moins que les 8 octets d'un en-tête de boîte.
        $this->reader('abc')->readBoxes();
    }

    public function testThrowsOnMissingFile(): void
    {
        $this->expectException(CorruptedFileException::class);
        $this->expectExceptionMessage('Unreadable file');

        new IsoBmffBoxReader('/chemin/inexistant.cr3');
    }

    public function testReadsUuidBox(): void
    {
        // Une boîte uuid porte 16 octets d'UUID juste après le type. Plusieurs
        // boîtes uuid distinctes coexistent dans un CR3 : le type ne suffit pas
        // à les distinguer, il faut lire l'UUID.
        $uuid = hex2bin('85c0b687820f11e08111f4ce462b6a48');
        $bytes = $this->box('uuid', $uuid . $this->box('PRVW', 'CIBLE'));

        $found = $this->reader($bytes)->findUuid($uuid);

        self::assertNotNull($found);
        self::assertSame('uuid', $found->type);
    }

    public function testFindsUuidNestedInsideMoov(): void
    {
        // Dans un CR3 réel, l'UUID Canon vit SOUS moov — jamais à la racine.
        // Une recherche limitée au premier niveau ne le trouverait pas.
        $uuid = (string) hex2bin('85c0b687820f11e08111f4ce462b6a48');
        $bytes = $this->box('ftyp', 'crx isom')
            . $this->box('moov', $this->box('uuid', $uuid . $this->box('PRVW', 'CIBLE')));

        self::assertNotNull($this->reader($bytes)->findUuid($uuid));
    }

    public function testFindUuidReturnsNullForOtherUuid(): void
    {
        $bytes = $this->box('uuid', hex2bin('00112233445566778899aabbccddeeff') . 'x');

        self::assertNull($this->reader($bytes)->findUuid(hex2bin('85c0b687820f11e08111f4ce462b6a48')));
    }

    public function testReadsPayload(): void
    {
        $reader = $this->reader($this->box('PRVW', 'CHARGE UTILE'));

        self::assertSame('CHARGE UTILE', $reader->readPayload($reader->readBoxes()[0]));
    }

    public function testThrowsWhenReadingBytesOutOfBounds(): void
    {
        $this->expectException(CorruptedFileException::class);
        $this->expectExceptionMessage('out of bounds');

        // readBytes est l'API publique du reader : Cr3PreviewParser l'appellera
        // avec des offsets issus du fichier, donc non fiables.
        $this->reader($this->box('PRVW', 'court'))->readBytes(9999, 10);
    }

    public function testThrowsWhenReadingZeroBytes(): void
    {
        $this->expectException(CorruptedFileException::class);

        $this->reader($this->box('PRVW', 'court'))->readBytes(8, 0);
    }

    public function testFindUuidIgnoresBoxesTooShortForAnUuid(): void
    {
        // Une boîte uuid dont le payload fait moins de 16 octets ne peut pas
        // porter d'UUID : l'ignorer plutôt que de lire hors bornes.
        $bytes = $this->box('uuid', 'court');

        self::assertNull($this->reader($bytes)->findUuid(str_repeat("\x00", 16)));
    }

    public function testFindsBoxNestedInUuidBox(): void
    {
        // Les filles d'une boîte uuid commencent APRÈS les 16 octets d'UUID :
        // sans ce décalage, on lirait l'UUID comme un en-tête de boîte.
        $uuid = hex2bin('85c0b687820f11e08111f4ce462b6a48');
        $bytes = $this->box('uuid', $uuid . $this->box('PRVW', 'CIBLE'));

        $found = $this->reader($bytes)->find('PRVW');

        self::assertNotNull($found);
        self::assertSame('PRVW', $found->type);
    }

    public function testLimitsRecursionDepth(): void
    {
        // Un fichier hostile peut imbriquer des boîtes à l'infini. On s'arrête
        // au lieu de saturer la pile.
        $inner = $this->box('PRVW', 'TROP PROFOND');

        for ($i = 0; $i < 12; ++$i) {
            $inner = $this->box('moov', $inner);
        }

        self::assertNull($this->reader($inner)->find('PRVW'));
    }

    public function testDoesNotRecurseIntoLeafBoxes(): void
    {
        // mdat contient des données brutes : les interpréter comme des boîtes
        // produirait n'importe quoi. Seuls les conteneurs connus sont explorés.
        $bytes = $this->box('mdat', $this->box('PRVW', 'FAUX POSITIF'));

        self::assertNull($this->reader($bytes)->find('PRVW'));
    }

    /**
     * Une boîte ISO-BMFF : taille totale (en-tête compris), type, payload.
     * Toujours big-endian, quel que soit l'appareil.
     */
    private function box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)) . $type . $payload;
    }

    private function reader(string $bytes): IsoBmffBoxReader
    {
        $path = tempnam(sys_get_temp_dir(), 'cr3');
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return new IsoBmffBoxReader($path);
    }
}
