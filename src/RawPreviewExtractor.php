<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor;

use RonanLenouvel\RawPreviewExtractor\Exception\UnsupportedFormatException;
use RonanLenouvel\RawPreviewExtractor\Format\Format;
use RonanLenouvel\RawPreviewExtractor\Format\FormatDetector;
use RonanLenouvel\RawPreviewExtractor\Format\FormatDetectorInterface;
use RonanLenouvel\RawPreviewExtractor\Parser\Cr3\Cr3PreviewParser;
use RonanLenouvel\RawPreviewExtractor\Parser\PreviewParserInterface;
use RonanLenouvel\RawPreviewExtractor\Parser\Tiff\TiffPreviewParser;

/**
 * Façade du package : détecte le format, délègue au parseur qui le prend en charge.
 *
 * Elle ne parse rien elle-même. La résolution se fait par **clé dans une map
 * injectée**, jamais par un `switch` sur le format : ajouter RAF ou ORF revient
 * à câbler une entrée de plus, sans toucher à cette classe.
 *
 * ```php
 * $extractor = RawPreviewExtractor::createDefault();
 * $preview = $extractor->extract('/photos/IMG_0042.CR2');
 * ```
 */
final class RawPreviewExtractor implements RawPreviewExtractorInterface
{
    /** @var array<string, PreviewParserInterface> */
    private readonly array $parsers;

    /**
     * @param FormatDetectorInterface                 $detector identifie le format par signature
     * @param iterable<string, PreviewParserInterface> $parsers  parseurs indexés par
     *                                                           {@see Format::value} ; un
     *                                                           `iterable` pour accepter aussi
     *                                                           bien un tableau que les services
     *                                                           taggés de Symfony
     */
    public function __construct(
        private readonly FormatDetectorInterface $detector,
        iterable $parsers,
    ) {
        $this->parsers = is_array($parsers) ? $parsers : iterator_to_array($parsers);
    }

    /**
     * Construit un extracteur câblé avec les parseurs standards.
     *
     * Raccourci pour les projets sans conteneur d'injection : une ligne au lieu
     * de l'assemblage manuel. Sous Symfony, le bundle câble les mêmes services
     * et c'est lui qui fait foi.
     */
    public static function createDefault(): self
    {
        $tiff = new TiffPreviewParser();

        return new self(new FormatDetector(), [
            Format::CR2->value => $tiff,
            Format::NEF->value => $tiff,
            Format::ARW->value => $tiff,
            Format::DNG->value => $tiff,
            Format::CR3->value => new Cr3PreviewParser(),
        ]);
    }

    public function extract(string $path): ExtractedPreview
    {
        $format = $this->detector->detect($path);
        $parser = null === $format ? null : ($this->parsers[$format->value] ?? null);

        if (null === $format || null === $parser) {
            throw new UnsupportedFormatException(sprintf(
                'Format non supporté : %s n\'est pas un RAW que ce package sait lire.',
                basename($path),
            ));
        }

        return $parser->extract($path, $format);
    }

    public function supports(string $path): bool
    {
        $format = $this->detector->detect($path);

        // Le détecteur peut reconnaître un format qu'aucun parseur ne traite :
        // supports() doit refléter ce que la façade sait vraiment faire.
        return null !== $format && isset($this->parsers[$format->value]);
    }

    /**
     * Un parseur est-il câblé pour ce format ?
     *
     * Sert aux tests de câblage — de la fabrique comme du bundle — pour vérifier
     * qu'aucun cas de {@see Format} n'a été oublié.
     */
    public function hasParserFor(Format $format): bool
    {
        return isset($this->parsers[$format->value]);
    }
}
