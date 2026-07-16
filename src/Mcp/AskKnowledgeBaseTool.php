<?php

namespace App\Mcp;

use App\Rag\RagQueryService;
use Mcp\Capability\Attribute\McpTool;

final class AskKnowledgeBaseTool
{
    public function __construct(
        private readonly RagQueryService $ragQueryService,
    ) {
    }

    /**
     * @return array{answer: string, sources: list<string>}
     */
    #[McpTool(
        name: 'ask_knowledge_base',
        description: 'Asks a natural-language question about the documents indexed in the RAG knowledge base and returns an answer with its sources.',
    )]
    public function ask(string $question): array
    {
        $answer = $this->ragQueryService->ask($question);

        return [
            'answer' => $answer->answer,
            'sources' => $answer->sources,
        ];
    }
}
