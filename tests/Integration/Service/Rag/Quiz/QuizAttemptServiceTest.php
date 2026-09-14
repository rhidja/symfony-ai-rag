<?php

namespace App\Tests\Integration\Service\Rag\Quiz;

use App\Repository\Rag\QuizAttemptRepository;
use App\Service\Rag\Quiz\Dto\GeneratedQuestion;
use App\Service\Rag\Quiz\Dto\GeneratedQuizPayload;
use App\Service\Rag\Quiz\Exception\QuizAttemptNotFoundException;
use App\Service\Rag\Quiz\QuizAttemptService;
use App\Service\Rag\Quiz\QuizGenerator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class QuizAttemptServiceTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    /**
     * @var list<string>
     */
    private array $createdSessionIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement(
            "DELETE FROM document_chunks WHERE metadata->>'_source' LIKE 'quiz-attempt-service-test://%'",
        );

        if ([] !== $this->createdSessionIds) {
            $this->connection->executeStatement(
                'DELETE FROM quiz_attempt WHERE session_id IN ('.implode(',', array_fill(0, \count($this->createdSessionIds), '?')).')',
                $this->createdSessionIds,
            );
        }

        parent::tearDown();
    }

    /**
     * A real session id (36-char UUID, like VisitorSessionResolver produces) so
     * it fits the quiz_attempt.session_id column and stays realistic; tracked
     * for cleanup since it carries no test-specific marker to filter on later.
     */
    private function uniqueSessionId(): string
    {
        $sessionId = Uuid::v4()->toRfc4122();
        $this->createdSessionIds[] = $sessionId;

        return $sessionId;
    }

    private function createService(GeneratedQuizPayload $payload): QuizAttemptService
    {
        $platform = new InMemoryPlatform(
            static fn (Model $model, $input, array $options): ObjectResult => new ObjectResult($payload),
        );

        $generator = new QuizGenerator(
            $this->connection,
            $platform,
            'gpt-4o-mini',
            \count($payload->questions),
            'document_chunks',
            \dirname(__DIR__, 5).'/config/prompts/quiz_generation_system_prompt.md',
        );

        /** @var QuizAttemptRepository $repository */
        $repository = $this->entityManager->getRepository(\App\Entity\Rag\QuizAttempt::class);

        return new QuizAttemptService($generator, $repository, $this->entityManager, $this->connection, 'document_chunks');
    }

    private function insertChunk(string $source, string $text, ?string $title = null): void
    {
        $metadata = ['_text' => $text, '_source' => $source];
        if (null !== $title) {
            $metadata['_title'] = $title;
        }

        $this->connection->insert('document_chunks', [
            'id' => Uuid::v4()->toRfc4122(),
            'embedding' => '['.implode(',', array_fill(0, 1536, 0)).']',
            'metadata' => json_encode($metadata, \JSON_THROW_ON_ERROR),
        ], [
            'id' => ParameterType::STRING,
            'embedding' => ParameterType::STRING,
            'metadata' => ParameterType::STRING,
        ]);
    }

    public function testStartAnswerCompletesAttemptAndComputesScore(): void
    {
        $source = 'quiz-attempt-service-test://manual.pdf';
        $this->insertChunk($source, 'The device resets when you hold the button for 5 seconds.');

        $payload = new GeneratedQuizPayload([
            new GeneratedQuestion('Q1?', ['A', 'B'], [0], false, 'exp', $source),
            new GeneratedQuestion('Q2?', ['A', 'B', 'C'], [1, 2], true, 'exp', $source),
        ]);

        $service = $this->createService($payload);

        $attempt = $service->start($this->uniqueSessionId(), $source);

        self::assertSame(2, $attempt->getTotalQuestions());
        self::assertFalse($attempt->isComplete());
        self::assertNull($attempt->getScore());

        $attempt = $service->answer($attempt->getId(), 0, [0]); // correct
        self::assertFalse($attempt->isComplete());

        $attempt = $service->answer($attempt->getId(), 1, [1]); // incorrect (partial)
        self::assertTrue($attempt->isComplete());
        self::assertSame(1, $attempt->getScore());
        self::assertNotNull($attempt->getCompletedAt());
    }

    public function testAnswerThrowsForUnknownAttempt(): void
    {
        $service = $this->createService(new GeneratedQuizPayload([]));

        $this->expectException(QuizAttemptNotFoundException::class);

        $service->answer(Uuid::v4()->toRfc4122(), 0, [0]);
    }

    public function testGetHistoryReturnsAttemptsForSessionOnly(): void
    {
        $source = 'quiz-attempt-service-test://manual.pdf';
        $this->insertChunk($source, 'Some content.');

        $payload = new GeneratedQuizPayload([
            new GeneratedQuestion('Q1?', ['A', 'B'], [0], false, 'exp', $source),
        ]);
        $service = $this->createService($payload);

        $sessionA = $this->uniqueSessionId();
        $sessionB = $this->uniqueSessionId();
        $service->start($sessionA, $source);
        $service->start($sessionB, $source);

        $history = $service->getHistory($sessionA);

        self::assertCount(1, $history);
        self::assertSame($sessionA, $history[0]->getSessionId());
    }

    public function testListAvailableDocumentsReturnsDistinctSourcesWithTitle(): void
    {
        $source = 'quiz-attempt-service-test://manual.pdf';
        $this->insertChunk($source, 'First chunk.', 'The Manual');
        $this->insertChunk($source, 'Second chunk.');

        $service = $this->createService(new GeneratedQuizPayload([]));

        $documents = array_filter(
            $service->listAvailableDocuments(),
            static fn (array $document): bool => $document['source'] === $source,
        );

        self::assertCount(1, $documents);
        self::assertSame('The Manual', array_values($documents)[0]['title']);
    }
}
