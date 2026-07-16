<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Parser;

use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;
use RonanLenouvel\RawPreviewExtractor\Exception\PreviewNotFoundException;
use RonanLenouvel\RawPreviewExtractor\ExtractedPreview;
use RonanLenouvel\RawPreviewExtractor\Format\Format;

/**
 * Extracts the JPEG preview of a RAW file of a given container.
 *
 * One implementation per container family: TIFF for CR2/NEF/ARW/DNG, ISO-BMFF
 * for CR3. All are interchangeable behind this contract — that is what lets the
 * facade resolve through a simple `Format → parser` map, and welcome a new
 * format without being modified.
 */
interface PreviewParserInterface
{
    /**
     * @param string $path   path of the RAW file
     * @param Format $format format already identified by the detector
     *
     * @throws PreviewNotFoundException if the file is valid but without a preview
     * @throws CorruptedFileException   if the file is unreadable or inconsistent
     */
    public function extract(string $path, Format $format): ExtractedPreview;
}
