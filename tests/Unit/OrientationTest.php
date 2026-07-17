<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RonanLenouvel\RawPreviewExtractor\Orientation;

#[CoversClass(Orientation::class)]
final class OrientationTest extends TestCase
{
    #[DataProvider('provideExifValues')]
    public function testMapsEveryExifValue(int $exif, Orientation $expected): void
    {
        // Les 8 valeurs de la spec EXIF, toutes reconnues.
        self::assertSame($expected, Orientation::from($exif));
    }

    /**
     * @return iterable<string, array{int, Orientation}>
     */
    public static function provideExifValues(): iterable
    {
        yield '1 normal' => [1, Orientation::Normal];
        yield '2 miroir horizontal' => [2, Orientation::FlipHorizontal];
        yield '3 rotation 180' => [3, Orientation::Rotate180];
        yield '4 miroir vertical' => [4, Orientation::FlipVertical];
        yield '5 transpose' => [5, Orientation::Transpose];
        yield '6 rotation 90' => [6, Orientation::Rotate90];
        yield '7 transverse' => [7, Orientation::Transverse];
        yield '8 rotation 270' => [8, Orientation::Rotate270];
    }

    #[DataProvider('provideDegrees')]
    public function testReportsRotationInDegrees(Orientation $o, int $degrees): void
    {
        // Le degré est ce dont l'appelant a besoin : imagerotate() ou une
        // transform CSS le prennent directement.
        self::assertSame($degrees, $o->degrees());
    }

    /**
     * @return iterable<string, array{Orientation, int}>
     */
    public static function provideDegrees(): iterable
    {
        yield 'normal' => [Orientation::Normal, 0];
        yield 'miroir horizontal' => [Orientation::FlipHorizontal, 0];
        yield '180' => [Orientation::Rotate180, 180];
        yield 'miroir vertical' => [Orientation::FlipVertical, 180];
        yield 'transpose' => [Orientation::Transpose, 90];
        yield '90' => [Orientation::Rotate90, 90];
        yield 'transverse' => [Orientation::Transverse, 270];
        yield '270' => [Orientation::Rotate270, 270];
    }

    #[DataProvider('provideMirrored')]
    public function testReportsMirroring(Orientation $o, bool $mirrored): void
    {
        // Quatre des huit valeurs EXIF sont des miroirs, pas des rotations :
        // les traiter comme des rotations produirait une image inversée.
        self::assertSame($mirrored, $o->isMirrored());
    }

    /**
     * @return iterable<string, array{Orientation, bool}>
     */
    public static function provideMirrored(): iterable
    {
        yield 'normal' => [Orientation::Normal, false];
        yield 'miroir horizontal' => [Orientation::FlipHorizontal, true];
        yield '180' => [Orientation::Rotate180, false];
        yield 'miroir vertical' => [Orientation::FlipVertical, true];
        yield 'transpose' => [Orientation::Transpose, true];
        yield '90' => [Orientation::Rotate90, false];
        yield 'transverse' => [Orientation::Transverse, true];
        yield '270' => [Orientation::Rotate270, false];
    }

    public function testDetectsWhenNothingNeedsToBeDone(): void
    {
        // Le cas courant : la plupart des photos sont droites. L'appelant doit
        // pouvoir l'écarter d'un seul test.
        self::assertTrue(Orientation::Normal->isUpright());

        self::assertFalse(Orientation::Rotate90->isUpright());
        self::assertFalse(Orientation::FlipHorizontal->isUpright());
    }

    public function testSwapsDimensionsOnQuarterTurns(): void
    {
        // Une rotation de 90° ou 270° échange largeur et hauteur : sans cela,
        // un appelant qui dimensionne son conteneur avant de pivoter se trompe.
        self::assertTrue(Orientation::Rotate90->swapsDimensions());
        self::assertTrue(Orientation::Rotate270->swapsDimensions());
        self::assertTrue(Orientation::Transpose->swapsDimensions());
        self::assertTrue(Orientation::Transverse->swapsDimensions());

        self::assertFalse(Orientation::Normal->swapsDimensions());
        self::assertFalse(Orientation::Rotate180->swapsDimensions());
        self::assertFalse(Orientation::FlipHorizontal->swapsDimensions());
    }

    public function testFallsBackToNormalForUnknownValue(): void
    {
        // Un tag hors spec (0, 9, 42…) ne doit pas faire échouer une extraction
        // par ailleurs réussie : on suppose l'image droite.
        self::assertSame(Orientation::Normal, Orientation::fromExif(0));
        self::assertSame(Orientation::Normal, Orientation::fromExif(9));
        self::assertSame(Orientation::Normal, Orientation::fromExif(null));
    }
}
