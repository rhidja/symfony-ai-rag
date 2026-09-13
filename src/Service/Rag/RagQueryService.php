<?php

namespace App\Service\Rag;

use App\Service\Rag\Model\RagAnswer;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;

final class RagQueryService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are a helpful assistant answering questions about an internal knowledge base.
        Answer ONLY using the context provided below. Cite the source document(s) you used.
        If the context does not contain enough information to answer, say clearly that you
        don't know instead of making up an answer.
        PROMPT;

    public function __construct(
        #[Target('default')]
        private readonly VectorizerInterface $vectorizer,
        #[Target('postgres_default')]
        private readonly StoreInterface $store,
        private readonly PlatformInterface $platform,
        #[Autowire(param: 'app.rag.chat_model')]
        private readonly string $chatModel,
        #[Autowire(param: 'app.rag.top_k')]
        private readonly int $topK,
    ) {
    }

    public function ask(string $question): RagAnswer
    {
        $vector = $this->vectorizer->vectorize($question);
        $documents = iterator_to_array($this->store->query(new VectorQuery($vector), ['limit' => $this->topK]));

        if ([] === $documents) {
            return new RagAnswer(
                "Je ne trouve pas d'information sur ce sujet dans la base.",
                [],
            );
        }

        $context = [];
        $sources = [];
        foreach ($documents as $document) {
            $metadata = $document->getMetadata();
            $source = $metadata->hasSource() ? $metadata->getSource() : (string) $document->getId();
            $text = $metadata->hasText() ? $metadata->getText() : '';

            $header = "Source: {$source}";
            if ($metadata->hasTitle()) {
                $header .= "\nTitle: {$metadata->getTitle()}";
            }
            if (\is_string($metadata['_author'] ?? null)) {
                $header .= "\nAuthor: {$metadata['_author']}";
            }

            $context[] = "{$header}\n{$text}";
            $sources[$source] = $source;
        }

        $messages = new MessageBag(
            Message::forSystem(self::SYSTEM_PROMPT),
            Message::ofUser(\sprintf(
                "Context:\n%s\n\nQuestion: %s",
                implode("\n\n", $context),
                $question,
            )),
        );

        $answer = $this->platform->invoke($this->chatModel, $messages)->asText();

        return new RagAnswer($answer, array_values($sources));
    }
}
