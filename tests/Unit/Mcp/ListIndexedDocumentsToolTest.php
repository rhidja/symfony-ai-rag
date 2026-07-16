<?php

namespace App\Tests\Unit\Mcp;

use App\Mcp\ListIndexedDocumentsTool;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class ListIndexedDocumentsToolTest extends TestCase
{
    public function testListReturnsSourcesWithChunkCounts(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quote')->willReturnCallback(static fn (string $value): string => "'".$value."'");
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $value): string => '"'.$value.'"');
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['source' => '/docs/manual.pdf', 'chunks' => '12'],
                ['source' => '/docs/faq.pdf', 'chunks' => '3'],
            ]);

        $tool = new ListIndexedDocumentsTool($connection, 'document_chunks');

        $result = $tool->list();

        self::assertSame([
            'documents' => [
                ['source' => '/docs/manual.pdf', 'chunks' => 12],
                ['source' => '/docs/faq.pdf', 'chunks' => 3],
            ],
        ], $result);
    }

    public function testListReturnsEmptyArrayWhenNoDocumentsIndexed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quote')->willReturnCallback(static fn (string $value): string => "'".$value."'");
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $value): string => '"'.$value.'"');
        $connection->method('fetchAllAssociative')->willReturn([]);

        $tool = new ListIndexedDocumentsTool($connection, 'document_chunks');

        self::assertSame(['documents' => []], $tool->list());
    }
}
