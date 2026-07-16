<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor;

use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;
use RonanLenouvel\RawPreviewExtractor\Exception\PreviewNotFoundException;
use RonanLenouvel\RawPreviewExtractor\Exception\RawPreviewExtractorException;
use RonanLenouvel\RawPreviewExtractor\Exception\UnsupportedFormatException;

/**
 * Extracts the JPEG preview embedded in a RAW file.
 *
 * This is the package's entry point — the only type to know in order to use it:
 *
 * ```php
 * $extractor = RawPreviewExtractor::createDefault();
 *
 * try {
 *     $preview = $extractor->extract('/photos/IMG_0042.CR2');
 *     file_put_contents('/cache/thumb.jpg', $preview->jpegData);
 * } catch (RawPreviewExtractorException) {
 *     // no thumbnail: the caller degrades as it sees fit
 * }
 * ```
 *
 * Under Symfony, the bundle registers an autowirable implementation of this
 * interface: it is the one to type-hint, never the concrete class.
 */
interface RawPreviewExtractorInterface
{
    /**
     * Extracts the JPEG preview from the RAW file.
     *
     * Every exception thrown implements {@see RawPreviewExtractorException}: a
     * single `catch` is enough to degrade gracefully.
     *
     * @param string $path path of the RAW file
     *
     * @throws UnsupportedFormatException the file is not a supported RAW
     * @throws PreviewNotFoundException   valid file, but without a JPEG preview
     * @throws CorruptedFileException     unreadable or structurally invalid file
     */
    public function extract(string $path): ExtractedPreview;

    /**
     * Can this file be processed by this package?
     *
     * The answer rests on the file's **binary signature**, never on its
     * extension: a CR2 renamed to `.jpg` returns `true`.
     *
     * Never throws — a missing or unreadable file simply returns `false`.
     */
    public function supports(string $path): bool;
}
