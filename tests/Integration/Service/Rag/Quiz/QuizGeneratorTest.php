<?php

namespace App\Tests\Integration\Service\Rag\Quiz;

use App\Service\Rag\Quiz\Dto\GeneratedQuestion;
use App\Service\Rag\Quiz\Dto\GeneratedQuizPayload;
use App\Service\Rag\Quiz\Exception\QuizGenerationException;
use App\Service\Rag\Quiz\QuizGenerator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class QuizGeneratorTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement(
            "DELETE FROM document_chunks WHERE metadata->>'_source' LIKE 'quiz-generator-test://%'",
        );

        parent::tearDown();
    }

    public function testGenerateReturnsQuestionsFromSampledChunks(): void
    {
        $source = 'quiz-generator-test://manual.pdf';
        $this->insertChunk($source, 'The device resets when you hold the button for 5 seconds.');
        $this->insertChunk($source, 'The warranty covers the device for 2 years.');

        $payload = new GeneratedQuizPayload([
            new GeneratedQuestion(
                prompt: 'How long do you hold the button to reset the device?',
                options: ['2 seconds', '5 seconds', '10 seconds'],
                correctIndices: [1],
                multiple: false,
                explanation: 'The manual states the button must be held for 5 seconds.',
                source: $source,
            ),
        ]);

        $platform = new InMemoryPlatform(
            static fn (Model $model, $input, array $options): ObjectResult => new ObjectResult($payload),
        );

        $generator = new QuizGenerator($this->connection, $platform, 'gpt-4o-mini', 1, 'document_chunks', $this->promptPath());

        $questions = $generator->generate($source);

        self::assertCount(1, $questions);
        self::assertSame('How long do you hold the button to reset the device?', $questions[0]['prompt']);
        self::assertSame([1], $questions[0]['correctIndices']);
        self::assertFalse($questions[0]['multiple']);
    }

    public function testGenerateThrowsWhenDocumentHasNoIndexedContent(): void
    {
        $platform = new InMemoryPlatform(
            static function (): never {
                self::fail('The platform should not be called when there is nothing to sample.');
            },
        );

        $generator = new QuizGenerator($this->connection, $platform, 'gpt-4o-mini', 5, 'document_chunks', $this->promptPath());

        $this->expectException(QuizGenerationException::class);

        $generator->generate('quiz-generator-test://does-not-exist.pdf');
    }

    public function testGenerateThrowsWhenModelReturnsWrongQuestionCount(): void
    {
        $source = 'quiz-generator-test://manual.pdf';
        $this->insertChunk($source, 'Some content.');

        $payload = new GeneratedQuizPayload([]);
        $platform = new InMemoryPlatform(
            static fn (Model $model, $input, array $options): ObjectResult => new ObjectResult($payload),
        );

        $generator = new QuizGenerator($this->connection, $platform, 'gpt-4o-mini', 3, 'document_chunks', $this->promptPath());

        $this->expectException(QuizGenerationException::class);

        $generator->generate($source);
    }

    public function testGenerateThrowsWhenACorrectIndexIsOutOfRange(): void
    {
        $source = 'quiz-generator-test://manual.pdf';
        $this->insertChunk($source, 'Some content.');

        $payload = new GeneratedQuizPayload([
            new GeneratedQuestion(
                prompt: 'Invalid question',
                options: ['A', 'B'],
                correctIndices: [5],
                multiple: false,
                explanation: 'n/a',
                source: $source,
            ),
        ]);
        $platform = new InMemoryPlatform(
            static fn (Model $model, $input, array $options): ObjectResult => new ObjectResult($payload),
        );

        $generator = new QuizGenerator($this->connection, $platform, 'gpt-4o-mini', 1, 'document_chunks', $this->promptPath());

        $this->expectException(QuizGenerationException::class);

        $generator->generate($source);
    }

    private function insertChunk(string $source, string $text): void
    {
        $this->connection->insert('document_chunks', [
            'id' => Uuid::v4()->toRfc4122(),
            'embedding' => '['.implode(',', array_fill(0, 1536, 0)).']',
            'metadata' => json_encode(['_text' => $text, '_source' => $source], \JSON_THROW_ON_ERROR),
        ], [
            'id' => ParameterType::STRING,
            'embedding' => ParameterType::STRING,
            'metadata' => ParameterType::STRING,
        ]);
    }

    private function promptPath(): string
    {
        return \dirname(__DIR__, 5).'/config/prompts/quiz_generation_system_prompt.md';
    }
}
