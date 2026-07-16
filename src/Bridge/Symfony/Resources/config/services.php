<?php

declare(strict_types=1);

use RonanLenouvel\RawPreviewExtractor\Format\Format;
use RonanLenouvel\RawPreviewExtractor\Format\FormatDetector;
use RonanLenouvel\RawPreviewExtractor\Parser\Cr3\Cr3PreviewParser;
use RonanLenouvel\RawPreviewExtractor\Parser\Tiff\TiffPreviewParser;
use RonanLenouvel\RawPreviewExtractor\RawPreviewExtractor;
use RonanLenouvel\RawPreviewExtractor\RawPreviewExtractorInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * Explicit definitions, without autowiring or autoconfiguration.
 *
 * This is the most often violated bundle rule: a reusable bundle wires its
 * services by hand. Enabling autowiring here would pollute the configuration of
 * the consuming application.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('raw_preview_extractor.format_detector', FormatDetector::class);
    $services->set('raw_preview_extractor.parser.tiff', TiffPreviewParser::class);
    $services->set('raw_preview_extractor.parser.cr3', Cr3PreviewParser::class);

    // The map that carries the OCP: adding RAF or ORF in v2 will be done with a
    // line here, without touching the facade. The keys are the values of the
    // Format enum, built explicitly — an auto-collected tag would require
    // autoconfiguration, which is forbidden in a bundle.
    $services->set('raw_preview_extractor.extractor', RawPreviewExtractor::class)
        ->args([
            service('raw_preview_extractor.format_detector'),
            [
                Format::CR2->value => service('raw_preview_extractor.parser.tiff'),
                Format::NEF->value => service('raw_preview_extractor.parser.tiff'),
                Format::ARW->value => service('raw_preview_extractor.parser.tiff'),
                Format::DNG->value => service('raw_preview_extractor.parser.tiff'),
                Format::CR3->value => service('raw_preview_extractor.parser.cr3'),
            ],
        ]);

    // Only the interface is exposed: the parsers and the detector stay private,
    // they are implementation details.
    $services->alias(RawPreviewExtractorInterface::class, 'raw_preview_extractor.extractor')
        ->public();
};
