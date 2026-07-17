<?php

namespace App\Agent\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Source\HasSourcesInterface;
use Symfony\AI\Agent\Toolbox\Source\HasSourcesTrait;
use Symfony\AI\Agent\Toolbox\Source\Source;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Exposes the RAG vector store to an Agent as a callable tool - the LLM decides
 * when and how many times to search, unlike RagQueryService's single fixed search.
 */
#[AsTool(
    name: 'search_knowledge_base',
    description: 'Search the indexed documents for information relevant to a query. Returns matching excerpts with their source.',
)]
final class SimilaritySearch implements HasSourcesInterface
{
    use HasSourcesTrait;

    public function __construct(
        #[Target('default')]
        private readonly VectorizerInterface $vectorizer,
        #[Target('postgres_default')]
        private readonly StoreInterface $store,
    ) {
    }

    public function __invoke(string $query): string
    {
        $vector = $this->vectorizer->vectorize($query);
        $documents = iterator_to_array($this->store->query(new VectorQuery($vector), ['limit' => 5]));

        if ([] === $documents) {
            return 'No matching documents found.';
        }

        $blocks = [];
        foreach ($documents as $document) {
            $metadata = $document->getMetadata();
            $source = $metadata->hasSource() ? $metadata->getSource() : (string) $document->getId();
            $text = $metadata->hasText() ? $metadata->getText() : '';

            $blocks[] = \sprintf("Source: %s\n%s", $source, $text);
            $this->addSource(new Source($source, $source, $text));
        }

        return implode("\n\n---\n\n", $blocks);
    }
}
