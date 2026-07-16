<?php

namespace App\Tests\Unit\Mcp;

use App\Mcp\ListDocumentsTool;
use PHPUnit\Framework\TestCase;

final class ListDocumentsToolTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = tempnam(sys_get_temp_dir(), 'list_documents_test');
        unlink($this->projectDir);
        mkdir($this->projectDir.'/var/ebook', recursive: true);

        file_put_contents($this->projectDir.'/var/ebook/book.pdf', 'pdf content');
        file_put_contents($this->projectDir.'/var/ebook/notes.docx', 'docx content');
        file_put_contents($this->projectDir.'/var/ebook/ignored.txt', 'not a supported extension');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->projectDir.'/var/ebook/*') as $file) {
            unlink($file);
        }
        rmdir($this->projectDir.'/var/ebook');
        rmdir($this->projectDir.'/var');
        rmdir($this->projectDir);
    }

    public function testListReturnsOnlyPdfAndDocxFilesWithSize(): void
    {
        $tool = new ListDocumentsTool($this->projectDir);

        $result = $tool->list();

        self::assertSame([
            ['filename' => 'book.pdf', 'size_bytes' => \strlen('pdf content')],
            ['filename' => 'notes.docx', 'size_bytes' => \strlen('docx content')],
        ], $result['documents']);
    }

    public function testListReturnsEmptyArrayWhenDirectoryDoesNotExist(): void
    {
        $tool = new ListDocumentsTool(sys_get_temp_dir().'/does-not-exist-'.uniqid());

        self::assertSame(['documents' => []], $tool->list());
    }
}
