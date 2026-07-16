<?php

namespace App\Tests\Unit\Rag\Loader;

use App\Rag\Loader\CsvLoader;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Exception\RuntimeException;

final class CsvLoaderTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->fixturePath = tempnam(sys_get_temp_dir(), 'csv_loader_test').'.csv';
        file_put_contents($this->fixturePath, "name,city\nAlice,Paris\nBob,Lyon\n");
    }

    protected function tearDown(): void
    {
        if (is_file($this->fixturePath)) {
            unlink($this->fixturePath);
        }
    }

    public function testLoadConvertsRowsToTextWithHeaderLabels(): void
    {
        $documents = iterator_to_array((new CsvLoader())->load($this->fixturePath));

        self::assertCount(1, $documents);
        self::assertSame(
            "name: Alice, city: Paris\nname: Bob, city: Lyon",
            $documents[0]->getContent(),
        );
        self::assertSame($this->fixturePath, $documents[0]->getMetadata()->offsetGet(Metadata::KEY_SOURCE));
    }

    public function testLoadThrowsWhenSourceIsNull(): void
    {
        $this->expectException(InvalidArgumentException::class);

        iterator_to_array((new CsvLoader())->load(null));
    }

    public function testLoadThrowsWhenFileDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);

        iterator_to_array((new CsvLoader())->load('/nonexistent/path.csv'));
    }
}
