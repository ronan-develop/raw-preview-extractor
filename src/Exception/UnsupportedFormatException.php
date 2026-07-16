<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Exception;

/**
 * The file is not a RAW supported by this package.
 *
 * Two cases lead here: the signature is not recognised as any known format, or
 * the format is recognised but no parser is associated with it. From the
 * caller's point of view, both amount to the same thing — the file cannot be
 * processed.
 *
 * Use {@see \RonanLenouvel\RawPreviewExtractor\RawPreviewExtractorInterface::supports()}
 * to avoid it without going through a `try`/`catch`.
 */
final class UnsupportedFormatException extends \RuntimeException implements RawPreviewExtractorException
{
}
