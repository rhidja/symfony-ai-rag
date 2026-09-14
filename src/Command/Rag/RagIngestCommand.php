<?php

namespace App\Command\Rag;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:rag:ingest',
    description: 'Ingest PDF/DOCX/CSV/XLSX documents from a directory into the RAG vector store',
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
        $this->addArgument('directory', InputArgument::REQUIRED, 'Directory to scan for PDF/DOCX/CSV/XLSX documents');
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
            ->name(['*.pdf', '*.docx', '*.csv', '*.xlsx'])
            ->sortByName();

        $files = iterator_to_array($finder);

        if ([] === $files) {
            $io->warning('No PDF, DOCX, CSV, or XLSX files found in the given directory.');

            return Command::SUCCESS;
        }

        $io->title(\sprintf('Ingesting documents from "%s"', $directory));
        $progressBar = $io->createProgressBar(\count($files));
        $progressBar->start();

        $failures = [];

        foreach ($files as $file) {
            $path = $file->getRealPath();

            // Some PDFs (heavy embedded fonts) need well above the default CLI memory_limit to parse.
            $process = new Process([\PHP_BINARY, '-d', 'memory_limit=1536M', $this->projectDir.'/bin/console', 'app:rag:ingest-file', $path]);
            $process->setTimeout(300);

            try {
                $process->run();

                if (!$process->isSuccessful()) {
                    $failures[$path] = trim($process->getErrorOutput()) ?: trim($process->getOutput());
                }
            } catch (ProcessExceptionInterface $e) {
                // e.g. ProcessTimedOutException - one slow/hanging file must not abort the whole batch
                $failures[$path] = $e->getMessage();
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
