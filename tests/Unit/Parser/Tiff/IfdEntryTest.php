<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Tests\Unit\Parser\Tiff;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RonanLenouvel\RawPreviewExtractor\Parser\Tiff\IfdEntry;

#[CoversClass(IfdEntry::class)]
final class IfdEntryTest extends TestCase
{
    public function testValueReturnsFirstInteger(): void
    {
        self::assertSame(6, (new IfdEntry(0x0112, 3, 1, [6]))->value());
        self::assertNull((new IfdEntry(0x0112, 3, 1, []))->value());
    }

    public function testRationalDividesNumeratorByDenominator(): void
    {
        // 28/10 = f/2.8
        self::assertEqualsWithDelta(2.8, (new IfdEntry(0x829D, 5, 1, [28, 10]))->rational(), 0.0001);
    }

    public function testRationalIsNullWhenDenominatorIsZero(): void
    {
        // Un dénominateur nul est un mensonge, pas une valeur : jamais de division.
        self::assertNull((new IfdEntry(0x829D, 5, 1, [28, 0]))->rational());
    }

    public function testRationalIsNullWhenPairIncomplete(): void
    {
        self::assertNull((new IfdEntry(0x829D, 5, 1, [28]))->rational());
        self::assertNull((new IfdEntry(0x829D, 5, 1, []))->rational());
    }

    public function testRationalPairKeepsBothHalves(): void
    {
        self::assertSame([1, 250], (new IfdEntry(0x829A, 5, 1, [1, 250]))->rationalPair());
        self::assertNull((new IfdEntry(0x829A, 5, 1, [1]))->rationalPair());
    }
}
