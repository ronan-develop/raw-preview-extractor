<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Tests\Integration\Bridge\Symfony;

use PHPUnit\Framework\TestCase;
use RonanLenouvel\RawPreviewExtractor\Format\Format;
use RonanLenouvel\RawPreviewExtractor\RawPreviewExtractorInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Le bundle boote-t-il, et le service en sort-il **fonctionnel** ?
 *
 * Vérifier que le service existe ne suffit pas : une map `Format → parser`
 * incomplète produirait un service présent mais incapable d'extraire. Ces tests
 * exercent donc l'extraction réelle depuis le container.
 */
final class BundleIntegrationTest extends TestCase
{
    private TestKernel $kernel;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->kernel = new TestKernel('test', true);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        (new Filesystem())->remove($this->kernel->getCacheDir());

        // shutdown() rétablit l'error handler mais laisse en place l'exception
        // handler posé au boot. PHPUnit marque alors le test « risky » — et
        // failOnRisky est à true.
        restore_exception_handler();

        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        $this->tempFiles = [];
    }

    public function testInterfaceIsResolvableFromTheContainer(): void
    {
        // C'est l'alias public de l'interface qui est le contrat, pas le service
        // concret : c'est lui qu'on teste.
        $extractor = $this->kernel->getContainer()->get(RawPreviewExtractorInterface::class);

        self::assertInstanceOf(RawPreviewExtractorInterface::class, $extractor);
    }

    public function testServiceFromTheContainerActuallyExtracts(): void
    {
        // Le test qui compte : un service qui existe mais dont la map de parseurs
        // est incomplète passerait le test précédent et échouerait ici.
        $extractor = $this->kernel->getContainer()->get(RawPreviewExtractorInterface::class);

        $preview = $extractor->extract($this->cr2File());

        self::assertSame(Format::CR2, $preview->sourceFormat);
        self::assertSame([160, 120], [$preview->width, $preview->height]);
        self::assertNotFalse(getimagesizefromstring($preview->jpegData));
    }

    public function testInternalServicesStayPrivate(): void
    {
        // Les parseurs et le détecteur sont des détails d'implémentation :
        // les exposer figerait notre liberté de refactoring.
        $container = $this->kernel->getContainer();

        foreach ([
            'raw_preview_extractor.format_detector',
            'raw_preview_extractor.parser.tiff',
            'raw_preview_extractor.parser.cr3',
            'raw_preview_extractor.extractor',
        ] as $id) {
            self::assertFalse(
                $container->has($id),
                sprintf('le service « %s » ne doit pas être public', $id),
            );
        }
    }

    public function testEveryFormatHasAParserWiredInTheBundle(): void
    {
        // Garde-fou du câblage : ajouter un cas à Format sans l'ajouter à
        // services.php ferait mentir supports() en production. Ce test tombe
        // à la place de l'utilisateur.
        $extractor = $this->kernel->getContainer()->get(RawPreviewExtractorInterface::class);

        self::assertTrue(method_exists($extractor, 'hasParserFor'));

        foreach (Format::cases() as $format) {
            self::assertTrue(
                $extractor->hasParserFor($format),
                sprintf('aucun parseur câblé pour %s dans services.php', $format->name),
            );
        }
    }

    public function testSupportsWorksThroughTheContainer(): void
    {
        $extractor = $this->kernel->getContainer()->get(RawPreviewExtractorInterface::class);

        self::assertTrue($extractor->supports($this->cr2File()));
        self::assertFalse($extractor->supports(__FILE__));
    }

    /**
     * Un CR2 minimal mais crédible : signature Canon « CR » aux octets 8-9.
     */
    private function cr2File(): string
    {
        $image = imagecreatetruecolor(160, 120);
        imagefilledrectangle($image, 0, 0, 160, 120, imagecolorallocate($image, 90, 140, 200));

        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        $jpegOffset = 12 + 2 + 24 + 4;
        $ifd = pack('v', 2)
            . pack('v', 0x0201) . pack('v', 4) . pack('V', 1) . pack('V', $jpegOffset)
            . pack('v', 0x0202) . pack('v', 4) . pack('V', 1) . pack('V', strlen($jpeg))
            . pack('V', 0);

        $path = tempnam(sys_get_temp_dir(), 'bundle') . '.cr2';
        file_put_contents($path, 'II' . pack('v', 42) . pack('V', 12) . "CR\x02\x00" . $ifd . $jpeg);
        $this->tempFiles[] = $path;

        return $path;
    }
}
