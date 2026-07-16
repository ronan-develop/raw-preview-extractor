<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RonanLenouvel\RawPreviewExtractor\Exception\PreviewNotFoundException;
use RonanLenouvel\RawPreviewExtractor\Exception\RawPreviewExtractorException;
use RonanLenouvel\RawPreviewExtractor\Exception\UnsupportedFormatException;
use RonanLenouvel\RawPreviewExtractor\ExtractedPreview;
use RonanLenouvel\RawPreviewExtractor\Format\Format;
use RonanLenouvel\RawPreviewExtractor\Format\FormatDetectorInterface;
use RonanLenouvel\RawPreviewExtractor\Parser\PreviewParserInterface;
use RonanLenouvel\RawPreviewExtractor\RawPreviewExtractor;
use RonanLenouvel\RawPreviewExtractor\RawPreviewExtractorInterface;

#[CoversClass(RawPreviewExtractor::class)]
#[CoversClass(UnsupportedFormatException::class)]
final class RawPreviewExtractorTest extends TestCase
{
    public function testExtractDelegatesToTheParserOfTheDetectedFormat(): void
    {
        $expected = new ExtractedPreview('JPEG', 4, 3, Format::CR3);

        $extractor = new RawPreviewExtractor(
            $this->detectorReturning(Format::CR3),
            [
                Format::CR3->value => $this->parserReturning($expected),
                Format::CR2->value => $this->parserNeverCalled(),
            ],
        );

        self::assertSame($expected, $extractor->extract('/photo.cr3'));
    }

    public function testExtractPassesDetectedFormatToTheParser(): void
    {
        // Le parseur ne redétecte pas : la façade lui transmet le format, sinon
        // le fichier serait ouvert deux fois pour la même question.
        $parser = $this->createMock(PreviewParserInterface::class);
        $parser->expects(self::once())
            ->method('extract')
            ->with('/photo.nef', Format::NEF)
            ->willReturn(new ExtractedPreview('JPEG', 1, 1, Format::NEF));

        $extractor = new RawPreviewExtractor(
            $this->detectorReturning(Format::NEF),
            [Format::NEF->value => $parser],
        );

        $extractor->extract('/photo.nef');
    }

    public function testThrowsUnsupportedFormatForNonRaw(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        $this->expectExceptionMessage('non supporté');

        (new RawPreviewExtractor($this->detectorReturning(null), []))->extract('/photo.jpg');
    }

    public function testThrowsUnsupportedFormatWhenNoParserIsRegistered(): void
    {
        // Format reconnu, mais aucun parseur ne le prend en charge : du point de
        // vue de l'appelant, le fichier n'est pas supporté.
        $this->expectException(UnsupportedFormatException::class);

        (new RawPreviewExtractor($this->detectorReturning(Format::ARW), []))->extract('/photo.arw');
    }

    public function testSupportsReturnsTrueForKnownFormats(): void
    {
        $extractor = new RawPreviewExtractor(
            $this->detectorReturning(Format::DNG),
            [Format::DNG->value => $this->parserNeverCalled()],
        );

        self::assertTrue($extractor->supports('/photo.dng'));
    }

    public function testSupportsReturnsFalseForNonRaw(): void
    {
        $extractor = new RawPreviewExtractor($this->detectorReturning(null), []);

        self::assertFalse($extractor->supports('/photo.jpg'));
    }

    public function testSupportsReturnsFalseWhenNoParserIsRegistered(): void
    {
        // supports() reflète ce que la façade sait VRAIMENT faire, pas seulement
        // ce que le détecteur reconnaît.
        $extractor = new RawPreviewExtractor($this->detectorReturning(Format::CR3), []);

        self::assertFalse($extractor->supports('/photo.cr3'));
    }

    public function testAcceptsAnyIterableOfParsers(): void
    {
        // Symfony injecte un iterable de services taggés, pas un array : la
        // façade doit accepter les deux.
        $expected = new ExtractedPreview('JPEG', 2, 2, Format::CR2);
        $parsers = new \ArrayIterator([Format::CR2->value => $this->parserReturning($expected)]);

        $extractor = new RawPreviewExtractor($this->detectorReturning(Format::CR2), $parsers);

        self::assertSame($expected, $extractor->extract('/photo.cr2'));
    }

    public function testNewFormatNeedsNoChangeToTheFacade(): void
    {
        // Le test de l'OCP : brancher un parseur ne demande qu'une entrée de map.
        // Si ce test doit un jour être réécrit pour ajouter un format, le design
        // est raté.
        $expected = new ExtractedPreview('JPEG', 9, 9, Format::DNG);

        $extractor = new RawPreviewExtractor(
            $this->detectorReturning(Format::DNG),
            [Format::DNG->value => $this->parserReturning($expected)],
        );

        self::assertSame($expected, $extractor->extract('/inconnu.dng'));
    }

    public function testParserExceptionsPropagateUnchanged(): void
    {
        // La façade n'enveloppe pas : PreviewNotFoundException doit rester
        // distinguable d'UnsupportedFormatException chez l'appelant.
        $parser = $this->createStub(PreviewParserInterface::class);
        $parser->method('extract')->willThrowException(new PreviewNotFoundException('pas de preview'));

        $extractor = new RawPreviewExtractor(
            $this->detectorReturning(Format::CR2),
            [Format::CR2->value => $parser],
        );

        $this->expectException(PreviewNotFoundException::class);

        $extractor->extract('/photo.cr2');
    }

    public function testAllExceptionsShareTheMarkerInterface(): void
    {
        // Le contrat central : un seul catch suffit à l'appelant pour dégrader.
        $extractor = new RawPreviewExtractor($this->detectorReturning(null), []);

        try {
            $extractor->extract('/photo.jpg');
            self::fail('une exception était attendue');
        } catch (RawPreviewExtractorException $e) {
            self::assertInstanceOf(UnsupportedFormatException::class, $e);
        }
    }

    public function testCreateDefaultBuildsAWorkingExtractor(): void
    {
        // Le confort de l'utilisateur hors Symfony : une ligne, pas six.
        self::assertInstanceOf(
            RawPreviewExtractorInterface::class,
            RawPreviewExtractor::createDefault(),
        );
    }

    public function testCreateDefaultWiresAParserForEveryFormat(): void
    {
        // Garde-fou : ajouter un cas à Format sans câbler son parseur ferait
        // mentir supports(). Ce test tombe alors, à la place de l'utilisateur.
        $extractor = RawPreviewExtractor::createDefault();

        foreach (Format::cases() as $format) {
            self::assertTrue(
                $extractor->hasParserFor($format),
                sprintf('aucun parseur câblé pour %s', $format->name),
            );
        }
    }

    private function detectorReturning(?Format $format): FormatDetectorInterface
    {
        $detector = $this->createStub(FormatDetectorInterface::class);
        $detector->method('detect')->willReturn($format);

        return $detector;
    }

    private function parserReturning(ExtractedPreview $preview): PreviewParserInterface
    {
        $parser = $this->createStub(PreviewParserInterface::class);
        $parser->method('extract')->willReturn($preview);

        return $parser;
    }

    private function parserNeverCalled(): PreviewParserInterface
    {
        $parser = $this->createMock(PreviewParserInterface::class);
        $parser->expects(self::never())->method('extract');

        return $parser;
    }
}
