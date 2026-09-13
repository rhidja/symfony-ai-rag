<?php

namespace App\Tests\Unit\Service\Rag\Loader;

use App\Service\Rag\Loader\DocxLoader;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Exception\RuntimeException;

final class DocxLoaderTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Hello DOCX Test.');
        $section->addText('Second paragraph.');

        $this->fixturePath = tempnam(sys_get_temp_dir(), 'docx_loader_test').'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($this->fixturePath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->fixturePath)) {
            unlink($this->fixturePath);
        }
    }

    public function testLoadExtractsTextAndSourceMetadata(): void
    {
        $documents = iterator_to_array((new DocxLoader())->load($this->fixturePath));

        self::assertCount(1, $documents);
        self::assertStringContainsString('Hello DOCX Test.', $documents[0]->getContent());
        self::assertStringContainsString('Second paragraph.', $documents[0]->getContent());
        self::assertSame($this->fixturePath, $documents[0]->getMetadata()->offsetGet(Metadata::KEY_SOURCE));
    }

    public function testLoadThrowsWhenSourceIsNull(): void
    {
        $this->expectException(InvalidArgumentException::class);

        iterator_to_array((new DocxLoader())->load(null));
    }

    public function testLoadThrowsWhenFileDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);

        iterator_to_array((new DocxLoader())->load('/nonexistent/path.docx'));
    }
}
