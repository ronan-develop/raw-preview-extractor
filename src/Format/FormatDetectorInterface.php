<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Format;

/**
 * Identifies the RAW format of a file from its binary signature.
 *
 * A detector detects: it does not judge. An unreadable, missing or non-RAW file
 * yields `null`, never an exception — it is up to the caller to decide whether
 * the absence of a format is an error.
 */
interface FormatDetectorInterface
{
    /**
     * @param string $path absolute path of the file to identify
     *
     * @return Format|null the detected format, or null if the file is not a
     *                     supported RAW, is unreadable or does not exist
     */
    public function detect(string $path): ?Format;
}
