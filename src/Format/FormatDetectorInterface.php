<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Format;

/**
 * Identifie le format RAW d'un fichier à partir de sa signature binaire.
 *
 * Un détecteur détecte : il ne juge pas. Un fichier illisible, absent ou
 * non-RAW donne `null`, jamais une exception — c'est à l'appelant de décider
 * si l'absence de format est une erreur.
 */
interface FormatDetectorInterface
{
    /**
     * @param string $path chemin absolu du fichier à identifier
     *
     * @return Format|null le format détecté, ou null si le fichier n'est pas
     *                     un RAW supporté, est illisible ou n'existe pas
     */
    public function detect(string $path): ?Format;
}
