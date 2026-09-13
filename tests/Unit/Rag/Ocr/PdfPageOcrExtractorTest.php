<?php

namespace App\Tests\Unit\Rag\Ocr;

use App\Rag\Ocr\PdfPageOcrExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class PdfPageOcrExtractorTest extends TestCase
{
    public function testExtractPagesTextReturnsOcrResultForScannedPage(): void
    {
        $path = \dirname(__DIR__, 3).'/Fixtures/scanned.pdf';

        $extractor = new PdfPageOcrExtractor(languages: 'eng');

        $results = $extractor->extractPagesText($path, [1]);

        self::assertSame([1], array_keys($results));
        self::assertStringContainsString('SCANNED DOCUMENT TEXT', $results[1]);
    }

    public function testExtractPagesTextReusesCachedResult(): void
    {
        $path = \dirname(__DIR__, 3).'/Fixtures/scanned.pdf';

        $cache = new ArrayAdapter();
        $extractor = new PdfPageOcrExtractor(languages: 'eng', cache: $cache);

        $startedAt = microtime(true);
        $first = $extractor->extractPagesText($path, [1]);
        $firstCallDuration = microtime(true) - $startedAt;

        $startedAt = microtime(true);
        $second = $extractor->extractPagesText($path, [1]);
        $secondCallDuration = microtime(true) - $startedAt;

        self::assertSame($first, $second);
        // The second call is served from cache: no pdftoppm/tesseract
        // subprocess is spawned, so it must be substantially faster than
        // the real OCR run.
        self::assertLessThan($firstCallDuration / 2, $secondCallDuration);
    }
}
