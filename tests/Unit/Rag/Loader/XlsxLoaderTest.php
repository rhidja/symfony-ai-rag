<?php

namespace App\Tests\Unit\Rag\Loader;

use App\Rag\Loader\XlsxLoader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Exception\RuntimeException;

final class XlsxLoaderTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('People');
        $sheet->fromArray([
            ['name', 'city'],
            ['Alice', 'Paris'],
            ['Bob', 'Lyon'],
        ]);

        $this->fixturePath = tempnam(sys_get_temp_dir(), 'xlsx_loader_test').'.xlsx';
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($this->fixturePath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->fixturePath)) {
            unlink($this->fixturePath);
        }
    }

    public function testLoadConvertsRowsToTextWithSheetAndHeaderLabels(): void
    {
        $documents = iterator_to_array((new XlsxLoader())->load($this->fixturePath));

        self::assertCount(1, $documents);
        self::assertSame(
            "Sheet: People\nname: Alice, city: Paris\nname: Bob, city: Lyon",
            $documents[0]->getContent(),
        );
        self::assertSame($this->fixturePath, $documents[0]->getMetadata()->offsetGet(Metadata::KEY_SOURCE));
    }

    public function testLoadThrowsWhenSourceIsNull(): void
    {
        $this->expectException(InvalidArgumentException::class);

        iterator_to_array((new XlsxLoader())->load(null));
    }

    public function testLoadThrowsWhenFileDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);

        iterator_to_array((new XlsxLoader())->load('/nonexistent/path.xlsx'));
    }
}
