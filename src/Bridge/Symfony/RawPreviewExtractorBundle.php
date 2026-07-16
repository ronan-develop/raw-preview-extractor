<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Bridge\Symfony;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Optional bundle: registers the extractor as an autowirable service.
 *
 * It limits itself to **wiring** — no business logic, no parsing. The library
 * works identically without it, in Laravel as in plain PHP:
 *
 * ```php
 * $extractor = RawPreviewExtractor::createDefault();
 * ```
 *
 * Activation (Flex does it automatically):
 *
 * ```php
 * // config/bundles.php
 * return [
 *     RonanLenouvel\RawPreviewExtractor\Bridge\Symfony\RawPreviewExtractorBundle::class => ['all' => true],
 * ];
 * ```
 *
 * `RawPreviewExtractorInterface` then becomes injectable through autowiring.
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
        // Physical path: the @Bundle notation is deprecated for reusable
        // bundles.
        $loader = new PhpFileLoader($builder, new FileLocator(__DIR__ . '/Resources/config'));
        $loader->load('services.php');
    }
}
