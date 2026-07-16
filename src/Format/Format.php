<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Format;

/**
 * Formats RAW supportés par le package.
 *
 * La valeur de chaque cas est l'extension usuelle du format, en minuscules.
 * Elle sert d'identifiant stable — notamment comme clé de la map
 * Format → PreviewParserInterface — et non de critère de détection : celle-ci
 * repose exclusivement sur la signature binaire du fichier.
 */
enum Format: string
{
    /** Canon RAW v2 — conteneur TIFF. */
    case CR2 = 'cr2';

    /** Canon RAW v3 — conteneur ISO-BMFF. */
    case CR3 = 'cr3';

    /** Nikon Electronic Format — conteneur TIFF. */
    case NEF = 'nef';

    /** Sony Alpha RAW — conteneur TIFF. */
    case ARW = 'arw';

    /** Adobe Digital Negative — conteneur TIFF. */
    case DNG = 'dng';
}
