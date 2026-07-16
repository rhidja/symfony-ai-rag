<?php

namespace App\Tests\Unit\Mcp;

use App\Mcp\ReadDocumentTool;
use App\Rag\Loader\ExtensionAwareLoader;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\LoaderInterface;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

final class ReadDocumentToolTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = tempnam(sys_get_temp_dir(), 'read_document_test');
        unlink($this->projectDir);
        mkdir($this->projectDir.'/var/ebook', recursive: true);
        file_put_contents($this->projectDir.'/var/ebook/book.pdf', 'placeholder');
    }

    protected function tearDown(): void
    {
        @unlink($this->projectDir.'/var/ebook/book.pdf');
        @rmdir($this->projectDir.'/var/ebook');
        @rmdir($this->projectDir.'/var');
        @rmdir($this->projectDir);
    }

    public function testReadReturnsExtractedContent(): void
    {
        $pdfLoader = $this->createMock(LoaderInterface::class);
        $pdfLoader->method('load')->willReturn([
            new TextDocument(Uuid::v4(), 'The extracted book text.', new Metadata()),
        ]);

        $loader = new ExtensionAwareLoader(['pdf' => $pdfLoader]);
        $tool = new ReadDocumentTool($loader, $this->projectDir);

        $result = $tool->read('book.pdf');

        self::assertSame('book.pdf', $result['filename']);
        self::assertSame('The extracted book text.', $result['content']);
        self::assertFalse($result['truncated']);
    }

    public function testReadTruncatesContentBeyondMaxLength(): void
    {
        $pdfLoader = $this->createMock(LoaderInterface::class);
        $pdfLoader->method('load')->willReturn([
            new TextDocument(Uuid::v4(), str_repeat('a', 100), new Metadata()),
        ]);

        $loader = new ExtensionAwareLoader(['pdf' => $pdfLoader]);
        $tool = new ReadDocumentTool($loader, $this->projectDir);

        $result = $tool->read('book.pdf', 10);

        self::assertSame(str_repeat('a', 10), $result['content']);
        self::assertTrue($result['truncated']);
    }

    public function testReadRejectsPathTraversal(): void
    {
        $loader = new ExtensionAwareLoader(['pdf' => $this->createMock(LoaderInterface::class)]);
        $tool = new ReadDocumentTool($loader, $this->projectDir);

        $this->expectException(InvalidArgumentException::class);

        $tool->read('../outside.pdf');
    }

    public function testReadRejectsNonExistentFile(): void
    {
        $loader = new ExtensionAwareLoader(['pdf' => $this->createMock(LoaderInterface::class)]);
        $tool = new ReadDocumentTool($loader, $this->projectDir);

        $this->expectException(InvalidArgumentException::class);

        $tool->read('missing.pdf');
    }
}
