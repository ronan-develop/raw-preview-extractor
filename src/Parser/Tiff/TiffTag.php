<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Parser\Tiff;

/**
 * Tags TIFF/EXIF utiles à la localisation d'une preview JPEG.
 *
 * Cette énumération ne prétend pas couvrir TIFF 6.0 : seuls figurent les tags
 * que le package exploite réellement. Un tag absent d'ici n'est pas une erreur,
 * il est simplement ignoré au parcours.
 */
enum TiffTag: int
{
    /** Largeur de l'image décrite par l'IFD courant. */
    case ImageWidth = 0x0100;

    /** Hauteur de l'image décrite par l'IFD courant. */
    case ImageLength = 0x0101;

    /** Schéma de compression : 6 ou 7 = JPEG, 1 = non compressé. */
    case Compression = 0x0103;

    /** Fabricant de l'appareil. */
    case Make = 0x010F;

    /** Modèle de l'appareil. */
    case Model = 0x0110;

    /** Offset(s) des bandes de données image. */
    case StripOffsets = 0x0111;

    /** Taille(s) des bandes de données image. */
    case StripByteCounts = 0x0117;

    /** Offsets des sous-IFD — souvent l'emplacement de la preview. */
    case SubIfds = 0x014A;

    /** Offset du JPEG embarqué. */
    case JpegInterchangeFormat = 0x0201;

    /** Taille du JPEG embarqué, en octets. */
    case JpegInterchangeFormatLength = 0x0202;

    /** Pointeur vers l'IFD EXIF. */
    case ExifIfdPointer = 0x8769;

    /** Présent uniquement dans les DNG. */
    case DngVersion = 0xC612;
}
