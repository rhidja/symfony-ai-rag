<?php

namespace App\Mcp;

use Doctrine\DBAL\Connection;
use Mcp\Capability\Attribute\McpTool;
use Symfony\AI\Store\Document\Metadata;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ListIndexedDocumentsTool
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire(param: 'app.rag.store_table')]
        private readonly string $tableName,
    ) {
    }

    /**
     * @return array{documents: list<array{source: string, chunks: int}>}
     */
    #[McpTool(
        name: 'list_indexed_documents',
        description: 'Lists the documents currently indexed in the RAG knowledge base, with the number of chunks stored for each.',
    )]
    public function list(): array
    {
        $sourceKey = Metadata::KEY_SOURCE;

        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT metadata->>%s AS source, COUNT(*) AS chunks FROM %s GROUP BY source ORDER BY source',
                $this->connection->quote($sourceKey),
                $this->connection->quoteIdentifier($this->tableName),
            ),
        );

        return [
            'documents' => array_map(
                static fn (array $row): array => [
                    'source' => $row['source'],
                    'chunks' => (int) $row['chunks'],
                ],
                $rows,
            ),
        ];
    }
}
