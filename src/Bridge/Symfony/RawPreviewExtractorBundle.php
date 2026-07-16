<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Bridge\Symfony;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Bundle optionnel : enregistre l'extracteur comme service auto-wirable.
 *
 * Il se limite au **câblage** — aucune logique métier, aucun parsing. La
 * librairie fonctionne à l'identique sans lui, en Laravel comme en PHP nu :
 *
 * ```php
 * $extractor = RawPreviewExtractor::createDefault();
 * ```
 *
 * Activation (Flex le fait automatiquement) :
 *
 * ```php
 * // config/bundles.php
 * return [
 *     RonanLenouvel\RawPreviewExtractor\Bridge\Symfony\RawPreviewExtractorBundle::class => ['all' => true],
 * ];
 * ```
 *
 * `RawPreviewExtractorInterface` devient alors injectable par autowiring.
 */
final class RawPreviewExtractorBundle extends AbstractBundle
{
    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(
        array $config,
        ContainerConfigurator $container,
        ContainerBuilder $builder,
    ): void {
        // Chemin physique : la notation @Bundle est dépréciée pour les bundles
        // réutilisables.
        $loader = new PhpFileLoader($builder, new FileLocator(__DIR__ . '/Resources/config'));
        $loader->load('services.php');
    }
}
