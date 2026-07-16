<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Exception;

/**
 * The file is valid, but contains no usable JPEG preview.
 *
 * Typical cases: no tag points to a JPEG, or the announced size is zero.
 *
 * To be distinguished from {@see CorruptedFileException}: a **truncated** file, or
 * one whose offset lies, is corrupted, not devoid of a preview. This boundary is
 * tested — do not blur it.
 */
final class PreviewNotFoundException extends \RuntimeException implements RawPreviewExtractorException
{
}
