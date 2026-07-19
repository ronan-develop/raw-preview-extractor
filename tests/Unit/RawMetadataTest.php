<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RonanLenouvel\RawPreviewExtractor\RawMetadata;

#[CoversClass(RawMetadata::class)]
final class RawMetadataTest extends TestCase
{
    public function testIsEmptyWhenEveryFieldIsNull(): void
    {
        self::assertTrue((new RawMetadata())->isEmpty());
    }

    public function testIsNotEmptyWhenAnyFieldIsSet(): void
    {
        self::assertFalse((new RawMetadata(iso: 400))->isEmpty());
        self::assertFalse((new RawMetadata(cameraModel: 'Canon EOS R5'))->isEmpty());
    }

    public function testExposesValuesAsConstructed(): void
    {
        $meta = new RawMetadata(
            dateTimeOriginal: '2024:06:15 12:30:45',
            fNumber: 2.8,
            exposureTime: '1/250',
            iso: 400,
            focalLength: 50.0,
            lensModel: 'RF50mm F1.8 STM',
            cameraMake: 'Canon',
            cameraModel: 'Canon EOS R5',
        );

        self::assertSame('1/250', $meta->exposureTime);
        self::assertSame(400, $meta->iso);
        self::assertSame('Canon', $meta->cameraMake);
    }
}
