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
 * Définitions explicites, sans autowiring ni autoconfiguration.
 *
 * C'est la règle bundle la plus souvent violée : un bundle réutilisable câble
 * ses services à la main. Activer l'autowiring ici polluerait la configuration
 * de l'application consommatrice.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('raw_preview_extractor.format_detector', FormatDetector::class);
    $services->set('raw_preview_extractor.parser.tiff', TiffPreviewParser::class);
    $services->set('raw_preview_extractor.parser.cr3', Cr3PreviewParser::class);

    // La map qui porte l'OCP : ajouter RAF ou ORF en v2 se fera par une ligne
    // ici, sans toucher à la façade. Les clés sont les valeurs de l'enum Format,
    // construites explicitement — un tag auto-collecté exigerait de
    // l'autoconfiguration, interdite dans un bundle.
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

    // Seule l'interface est exposée : les parseurs et le détecteur restent
    // privés, ce sont des détails d'implémentation.
    $services->alias(RawPreviewExtractorInterface::class, 'raw_preview_extractor.extractor')
        ->public();
};
