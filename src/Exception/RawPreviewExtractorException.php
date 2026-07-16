<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Exception;

/**
 * Marker interface common to every exception of the package.
 *
 * It lets the caller degrade gracefully with a single `catch`, without knowing
 * the detail of the failure cases:
 *
 * ```php
 * try {
 *     $preview = $extractor->extract($path);
 * } catch (RawPreviewExtractorException) {
 *     // no thumbnail: carry on without one
 * }
 * ```
 *
 * Every exception thrown by this package implements it — a tested invariant.
 */
interface RawPreviewExtractorException extends \Throwable
{
}
