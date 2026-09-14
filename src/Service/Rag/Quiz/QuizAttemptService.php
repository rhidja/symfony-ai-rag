<?php

namespace App\Service\Rag\Quiz;

use App\Entity\Rag\QuizAttempt;
use App\Repository\Rag\QuizAttemptRepository;
use App\Service\Rag\Quiz\Exception\QuizAttemptNotFoundException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Store\Document\Metadata;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Thin orchestration layer over QuizGenerator and QuizAttemptRepository, kept
 * out of QuizComponent so the Live Component stays focused on presentation.
 */
final class QuizAttemptService
{
    public function __construct(
        private readonly QuizGenerator $generator,
        private readonly QuizAttemptRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        #[Autowire(param: 'app.rag.store_table')]
        private readonly string $storeTable,
    ) {
    }

    public function start(string $sessionId, string $documentSource): QuizAttempt
    {
        $questions = $this->generator->generate($documentSource);

        $attempt = new QuizAttempt($sessionId, $documentSource, $questions);

        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        return $attempt;
    }

    /**
     * @param list<int> $selectedIndices
     */
    public function answer(string $attemptId, int $questionIndex, array $selectedIndices): QuizAttempt
    {
        $attempt = $this->repository->find($attemptId);

        if (null === $attempt) {
            throw QuizAttemptNotFoundException::withId($attemptId);
        }

        $attempt->recordAnswer($questionIndex, $selectedIndices);

        if ($attempt->isComplete()) {
            $attempt->markCompleted();
        }

        $this->entityManager->flush();

        return $attempt;
    }

    /**
     * @return list<QuizAttempt>
     */
    public function getHistory(string $sessionId): array
    {
        return $this->repository->findBySession($sessionId);
    }

    /**
     * @return list<array{source: string, title: ?string}>
     */
    public function listAvailableDocuments(): array
    {
        $table = $this->connection->getDatabasePlatform()->quoteSingleIdentifier($this->storeTable);
        $textKey = Metadata::KEY_SOURCE;
        $titleKey = Metadata::KEY_TITLE;

        $rows = $this->connection->fetchAllAssociative(
            "SELECT metadata->>'{$textKey}' AS source, MAX(metadata->>'{$titleKey}') AS title
             FROM {$table}
             WHERE metadata->>'{$textKey}' IS NOT NULL
             GROUP BY metadata->>'{$textKey}'
             ORDER BY source",
        );

        return array_map(
            static fn (array $row): array => ['source' => $row['source'], 'title' => $row['title']],
            $rows,
        );
    }
}
