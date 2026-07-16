<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:rag:ingest',
    description: 'Ingest PDF/DOCX documents from a directory into the RAG vector store',
)]
final class RagIngestCommand extends Command
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
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

        $phpBinary = (new PhpExecutableFinder())->find();
        if (false === $phpBinary) {
            $io->error('Unable to locate the PHP binary to run per-file ingestion subprocesses.');

            return Command::FAILURE;
        }

        $io->title(\sprintf('Ingesting documents from "%s"', $directory));
        $progressBar = $io->createProgressBar(\count($files));
        $progressBar->start();

        $failures = [];

        foreach ($files as $file) {
            $path = $file->getRealPath();

            $process = new Process([$phpBinary, $this->projectDir.'/bin/console', 'app:rag:ingest-file', $path]);
            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful()) {
                $failures[$path] = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);

        $succeeded = \count($files) - \count($failures);
        $io->success(\sprintf('Ingested %d document(s).', $succeeded));

        if ([] !== $failures) {
            $io->warning(\sprintf('%d document(s) failed to ingest:', \count($failures)));
            foreach ($failures as $path => $message) {
                $io->text(\sprintf(' - %s: %s', $path, $message));
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
