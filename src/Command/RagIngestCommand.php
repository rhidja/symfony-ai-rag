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
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'app:rag:ingest',
    description: 'Ingest PDF/DOCX documents from a directory into the RAG vector store',
)]
final class RagIngestCommand extends Command
{
    private const SUPPORTED_EXTENSIONS = ['pdf', 'docx'];

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
        $this->addArgument('directory', InputArgument::REQUIRED, 'Directory to scan for PDF/DOCX documents');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = $input->getArgument('directory');

        if (!is_dir($directory)) {
            $io->error(\sprintf('Directory "%s" does not exist.', $directory));

            return Command::FAILURE;
        }

        $finder = (new Finder())
            ->files()
            ->in($directory)
            ->name(['*.pdf', '*.docx'])
            ->sortByName();

        $files = iterator_to_array($finder);

        if ([] === $files) {
            $io->warning('No PDF or DOCX files found in the given directory.');

            return Command::SUCCESS;
        }

        $io->title(\sprintf('Ingesting documents from "%s"', $directory));
        $progressBar = $io->createProgressBar(\count($files));
        $progressBar->start();

        foreach ($files as $file) {
            $path = $file->getRealPath();

            $this->removeExistingChunks($path);
            $this->indexer->index($path);

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);
        $io->success(\sprintf('Ingested %d document(s).', \count($files)));

        return Command::SUCCESS;
    }

    private function removeExistingChunks(string $source): void
    {
        $this->connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE metadata->>%s = :source', $this->connection->quoteIdentifier($this->tableName), $this->connection->quote(Metadata::KEY_SOURCE)),
            ['source' => $source],
        );
    }
}
