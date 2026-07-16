<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Tests\Integration\Bridge\Symfony;

use RonanLenouvel\RawPreviewExtractor\Bridge\Symfony\RawPreviewExtractorBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Kernel minimal servant à booter le bundle dans une application de test.
 *
 * Il n'enregistre que FrameworkBundle — dont notre bundle a besoin pour exister —
 * et le nôtre. Rien d'autre : le but est de prouver que le câblage tient debout
 * sans le reste d'une application.
 */
final class TestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new RawPreviewExtractorBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                // Sans cela, le gestionnaire d'erreurs de Symfony s'installe
                // globalement et PHPUnit marque le test « risky » : du code de
                // test ne doit pas laisser de handler derrière lui.
                'php_errors' => ['log' => false, 'throw' => false],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        // Un cache dédié, isolé et jetable : le test le supprime en tearDown.
        return sys_get_temp_dir() . '/rpe-bundle-test/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/rpe-bundle-test/log';
    }
}
