<?php

namespace App\Service\Rag\Quiz;

use App\Service\Rag\Quiz\Dto\GeneratedQuizPayload;
use App\Service\Rag\Quiz\Exception\QuizGenerationException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Store\Document\Metadata;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Generates a fixed-size multiple-choice quiz from a document already
 * indexed in the vector store, by sampling a handful of its chunks and
 * asking the chat model for a structured list of questions in one call.
 */
final class QuizGenerator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PlatformInterface $platform,
        #[Autowire(param: 'app.rag.chat_model')]
        private readonly string $chatModel,
        #[Autowire(param: 'app.rag.quiz_question_count')]
        private readonly int $questionCount,
        #[Autowire(param: 'app.rag.store_table')]
        private readonly string $storeTable,
        #[Autowire('%kernel.project_dir%/config/prompts/quiz_generation_system_prompt.md')]
        private readonly string $systemPromptPath,
    ) {
    }

    /**
     * @return list<array{prompt: string, options: list<string>, correctIndices: list<int>, multiple: bool, explanation: string, source: string}>
     *
     * @throws QuizGenerationException
     */
    public function generate(string $documentSource): array
    {
        $chunks = $this->sampleChunks($documentSource);

        if ([] === $chunks) {
            throw QuizGenerationException::documentNotFound($documentSource);
        }

        $messages = new MessageBag(
            Message::forSystem($this->systemPrompt()),
            Message::ofUser(\sprintf(
                "Nombre de questions à générer : %d\nDocument source : %s\n\nExtraits :\n%s",
                $this->questionCount,
                $documentSource,
                implode("\n\n---\n\n", $chunks),
            )),
        );

        $result = $this->platform->invoke($this->chatModel, $messages, [
            'response_format' => GeneratedQuizPayload::class,
        ]);

        $payload = $result->asObject();

        if (!$payload instanceof GeneratedQuizPayload) {
            throw QuizGenerationException::invalidModelOutput('unexpected response shape.');
        }

        $questions = $payload->questions;

        if (\count($questions) !== $this->questionCount) {
            throw QuizGenerationException::invalidModelOutput(\sprintf(
                'expected %d questions, got %d.',
                $this->questionCount,
                \count($questions),
            ));
        }

        $result = [];
        foreach ($questions as $question) {
            if (\count($question->options) < 2 || [] === $question->correctIndices) {
                throw QuizGenerationException::invalidModelOutput('a question has fewer than 2 options or no correct answer.');
            }

            foreach ($question->correctIndices as $correctIndex) {
                if (!\array_key_exists($correctIndex, $question->options)) {
                    throw QuizGenerationException::invalidModelOutput('a correct answer index is out of range.');
                }
            }

            $result[] = $question->toArray();
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function sampleChunks(string $documentSource): array
    {
        $sampleSize = max(6, $this->questionCount * 3);

        $table = $this->connection->getDatabasePlatform()->quoteSingleIdentifier($this->storeTable);
        $textKey = Metadata::KEY_TEXT;
        $sourceKey = Metadata::KEY_SOURCE;

        // The store keeps chunk content in the "metadata" JSONB column (key "_text"),
        // not in a dedicated column — there is no ORM entity for this table (see
        // doctrine.yaml's schema_filter), so it's queried directly via DBAL.
        $rows = $this->connection->fetchFirstColumn(
            "SELECT metadata->>'{$textKey}' AS content FROM {$table} WHERE metadata->>'{$sourceKey}' = :source ORDER BY random() LIMIT :sampleSize",
            ['source' => $documentSource, 'sampleSize' => $sampleSize],
            ['source' => ParameterType::STRING, 'sampleSize' => ParameterType::INTEGER],
        );

        return array_values(array_filter($rows, static fn (?string $text): bool => null !== $text && '' !== $text));
    }

    private function systemPrompt(): string
    {
        return file_get_contents($this->systemPromptPath);
    }
}
