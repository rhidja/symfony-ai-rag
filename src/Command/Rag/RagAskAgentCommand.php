<?php

namespace App\Command\Rag;

use App\Service\Rag\RagAgentQueryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Agent-based alternative to app:rag:ask (see RagAgentQueryService).
 */
#[AsCommand(
    name: 'app:rag:ask-agent',
    description: 'Ask a question to the RAG knowledge base via an Agent with tool-calling',
)]
final class RagAskAgentCommand extends Command
{
    public function __construct(
        private readonly RagAgentQueryService $ragAgentQueryService,
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

        $answer = $this->ragAgentQueryService->ask($question);

        $io->section('Answer (via Agent)');
        $io->writeln($answer->answer);

        if ([] !== $answer->sources) {
            $io->section('Sources');
            $io->listing($answer->sources);
        }

        return Command::SUCCESS;
    }
}
