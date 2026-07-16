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
 * Package facade: detects the format, delegates to the parser that handles it.
 *
 * It parses nothing itself. Resolution happens by **key in an injected map**,
 * never by a `switch` on the format: adding RAF or ORF amounts to wiring one
 * more entry, without touching this class.
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
     * @param FormatDetectorInterface                 $detector identifies the format by signature
     * @param iterable<string, PreviewParserInterface> $parsers  parsers indexed by
     *                                                           {@see Format::value}; an
     *                                                           `iterable` so as to accept
     *                                                           either an array or Symfony's
     *                                                           tagged services
     */
    public function __construct(
        private readonly FormatDetectorInterface $detector,
        iterable $parsers,
    ) {
        $this->parsers = is_array($parsers) ? $parsers : iterator_to_array($parsers);
    }

    /**
     * Builds an extractor wired with the standard parsers.
     *
     * A shortcut for projects without a dependency injection container: one line
     * instead of manual assembly. Under Symfony, the bundle wires the same
     * services and it is the bundle that is authoritative.
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
                'Unsupported format: %s is not a RAW file this package can read.',
                basename($path),
            ));
        }

        return $parser->extract($path, $format);
    }

    public function supports(string $path): bool
    {
        $format = $this->detector->detect($path);

        // The detector may recognise a format that no parser handles:
        // supports() must reflect what the facade can really do.
        return null !== $format && isset($this->parsers[$format->value]);
    }

    /**
     * Is a parser wired for this format?
     *
     * Used by the wiring tests — of the factory as well as the bundle — to check
     * that no {@see Format} case has been forgotten.
     */
    public function hasParserFor(Format $format): bool
    {
        return isset($this->parsers[$format->value]);
    }
}
