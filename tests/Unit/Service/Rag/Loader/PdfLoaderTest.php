<?php

namespace App\Tests\Unit\Service\Rag\Loader;

use App\Service\Rag\Loader\PdfLoader;
use App\Service\Rag\Ocr\PdfPageOcrExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Exception\RuntimeException;

final class PdfLoaderTest extends TestCase
{
    public function testLoadExtractsTextAndSourceMetadata(): void
    {
        $path = \dirname(__DIR__, 4).'/Fixtures/sample.pdf';

        $documents = iterator_to_array((new PdfLoader())->load($path));

        self::assertCount(1, $documents);
        self::assertSame('Hello PDF Test', $documents[0]->getContent());
        self::assertSame($path, $documents[0]->getMetadata()->offsetGet(Metadata::KEY_SOURCE));
    }

    public function testLoadFallsBackToOcrForScannedPages(): void
    {
        $path = \dirname(__DIR__, 4).'/Fixtures/scanned.pdf';

        // The test environment only ships the "eng" Tesseract language data,
        // unlike the app container which also installs "fra".
        $loader = new PdfLoader(ocr: new PdfPageOcrExtractor(languages: 'eng'));

        $documents = iterator_to_array($loader->load($path));

        self::assertCount(1, $documents);
        self::assertStringContainsString('SCANNED DOCUMENT TEXT', $documents[0]->getContent());
        self::assertSame($path, $documents[0]->getMetadata()->offsetGet(Metadata::KEY_SOURCE));
    }

    public function testLoadThrowsWhenSourceIsNull(): void
    {
        $this->expectException(InvalidArgumentException::class);

        iterator_to_array((new PdfLoader())->load(null));
    }

    public function testLoadThrowsWhenFileDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);

        iterator_to_array((new PdfLoader())->load('/nonexistent/path.pdf'));
    }
}
