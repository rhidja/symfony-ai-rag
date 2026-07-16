<?php

namespace App\Tests\Unit\Rag\Loader;

use App\Rag\Loader\PdfLoader;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Exception\RuntimeException;

final class PdfLoaderTest extends TestCase
{
    public function testLoadExtractsTextAndSourceMetadata(): void
    {
        $path = \dirname(__DIR__, 3).'/Fixtures/sample.pdf';

        $documents = iterator_to_array((new PdfLoader())->load($path));

        self::assertCount(1, $documents);
        self::assertSame('Hello PDF Test', $documents[0]->getContent());
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
