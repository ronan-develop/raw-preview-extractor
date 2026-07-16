<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Exception;

/**
 * The file is unreadable or structurally invalid.
 *
 * Covers: missing or unreadable file, missing or truncated header, offset
 * pointing outside the file, absurd size, inconsistent structure.
 *
 * To be distinguished from {@see PreviewNotFoundException}: a **truncated** file
 * is corrupted, not devoid of a preview. The distinction is tested — do not blur it.
 */
final class CorruptedFileException extends \RuntimeException implements RawPreviewExtractorException
{
}
