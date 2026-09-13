<?php

namespace App\Service\Rag;

use App\Service\Rag\Model\RagAnswer;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Toolbox\Source\SourceCollection;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Agent-based RAG: the LLM decides itself whether/how many times to call the
 * search_knowledge_base tool, instead of RagQueryService's single fixed
 * retrieve-then-generate pass.
 */
final class RagAgentQueryService
{
    public function __construct(
        #[Target('rag')]
        private readonly AgentInterface $agent,
    ) {
    }

    public function ask(string $question): RagAnswer
    {
        $result = $this->agent->call(new MessageBag(Message::ofUser($question)));

        $sources = [];
        $sourceCollection = $result->getMetadata()['sources'] ?? null;
        if ($sourceCollection instanceof SourceCollection) {
            foreach ($sourceCollection as $source) {
                $sources[$source->getReference()] = $source->getReference();
            }
        }

        return new RagAnswer((string) $result->getContent(), array_values($sources));
    }
}
