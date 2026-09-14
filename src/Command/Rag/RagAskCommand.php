<?php

namespace App\Command\Rag;

use App\Service\Rag\RagQueryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:rag:ask',
    description: 'Ask a question to the RAG knowledge base',
)]
final class RagAskCommand extends Command
{
    public function __construct(
        private readonly RagQueryService $ragQueryService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('question', InputArgument::REQUIRED, 'The question to ask');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $question = $input->getArgument('question');

        $answer = $this->ragQueryService->ask($question);

        $io->section('Answer');
        $io->writeln($answer->answer);

        if ([] !== $answer->sources) {
            $io->section('Sources');
            $io->listing($answer->sources);
        }

        return Command::SUCCESS;
    }
}
