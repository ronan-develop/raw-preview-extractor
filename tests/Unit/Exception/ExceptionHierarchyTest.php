<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;
use RonanLenouvel\RawPreviewExtractor\Exception\RawPreviewExtractorException;

#[CoversClass(CorruptedFileException::class)]
final class ExceptionHierarchyTest extends TestCase
{
    public function testCorruptedFileExceptionImplementsMarker(): void
    {
        // Le marker est le contrat central de la gestion d'erreur : l'appelant
        // doit pouvoir tout attraper d'un seul catch pour dégrader proprement.
        self::assertInstanceOf(
            RawPreviewExtractorException::class,
            new CorruptedFileException('peu importe'),
        );
    }

    public function testCorruptedFileExceptionIsAThrowable(): void
    {
        self::assertInstanceOf(\RuntimeException::class, new CorruptedFileException('x'));
    }

    public function testCarriesMessageAndPrevious(): void
    {
        // La signature est celle de RuntimeException : message, code, previous.
        $previous = new \RuntimeException('cause');
        $exception = new CorruptedFileException('offset hors bornes', 0, $previous);

        self::assertSame('offset hors bornes', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }
}
