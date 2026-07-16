<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor;

use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;
use RonanLenouvel\RawPreviewExtractor\Exception\PreviewNotFoundException;
use RonanLenouvel\RawPreviewExtractor\Exception\RawPreviewExtractorException;
use RonanLenouvel\RawPreviewExtractor\Exception\UnsupportedFormatException;

/**
 * Extrait la preview JPEG embarquée dans un fichier RAW.
 *
 * C'est le point d'entrée du package — le seul type à connaître pour l'utiliser :
 *
 * ```php
 * $extractor = RawPreviewExtractor::createDefault();
 *
 * try {
 *     $preview = $extractor->extract('/photos/IMG_0042.CR2');
 *     file_put_contents('/cache/thumb.jpg', $preview->jpegData);
 * } catch (RawPreviewExtractorException) {
 *     // pas de vignette : l'appelant dégrade comme il l'entend
 * }
 * ```
 *
 * Sous Symfony, le bundle enregistre une implémentation auto-wirable de cette
 * interface : c'est elle qu'on type-hinte, jamais la classe concrète.
 */
interface RawPreviewExtractorInterface
{
    /**
     * Extrait la preview JPEG du fichier RAW.
     *
     * Toutes les exceptions levées implémentent {@see RawPreviewExtractorException} :
     * un seul `catch` suffit pour dégrader proprement.
     *
     * @param string $path chemin du fichier RAW
     *
     * @throws UnsupportedFormatException le fichier n'est pas un RAW pris en charge
     * @throws PreviewNotFoundException   fichier valide, mais sans preview JPEG
     * @throws CorruptedFileException     fichier illisible ou structurellement invalide
     */
    public function extract(string $path): ExtractedPreview;

    /**
     * Ce fichier peut-il être traité par ce package ?
     *
     * La réponse repose sur la **signature binaire** du fichier, jamais sur son
     * extension : un CR2 renommé en `.jpg` renvoie `true`.
     *
     * Ne lève jamais — un fichier absent ou illisible renvoie simplement `false`.
     */
    public function supports(string $path): bool;
}
