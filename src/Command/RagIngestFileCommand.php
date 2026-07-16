<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\IndexerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Ingests a single file. Not meant to be run directly - app:rag:ingest runs one
 * subprocess per file so a memory exhaustion in one document (some PDFs are very
 * memory-hungry for smalot/pdfparser) does not abort the whole batch.
 */
#[AsCommand(
    name: 'app:rag:ingest-file',
    description: 'Ingest a single PDF/DOCX file into the RAG vector store (internal, used by app:rag:ingest)',
    hidden: true,
)]
final class RagIngestFileCommand extends Command
{
    public function __construct(
        #[Target('documents')]
        private readonly IndexerInterface $indexer,
        private readonly Connection $connection,
        #[Autowire(param: 'app.rag.store_table')]
        private readonly string $tableName,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to the PDF/DOCX file to ingest');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');

        $this->connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE metadata->>%s = :source', $this->connection->quoteIdentifier($this->tableName), $this->connection->quote(Metadata::KEY_SOURCE)),
            ['source' => $path],
        );

        $this->indexer->index($path);

        return Command::SUCCESS;
    }
}
