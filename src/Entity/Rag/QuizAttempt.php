<?php

namespace App\Entity\Rag;

use App\Repository\Rag\QuizAttemptRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A quiz session: a fixed set of generated questions for one document,
 * answered one by one, with a score computed once all of them are answered.
 *
 * Questions and answers are stored as JSON rather than as separate entities:
 * they are never queried independently of the attempt they belong to.
 *
 * @phpstan-type Question array{
 *     prompt: string,
 *     options: list<string>,
 *     correctIndices: list<int>,
 *     multiple: bool,
 *     explanation: string,
 *     source: string,
 * }
 * @phpstan-type Answer array{
 *     questionIndex: int,
 *     selectedIndices: list<int>,
 *     correct: bool,
 * }
 */
#[ORM\Entity(repositoryClass: QuizAttemptRepository::class)]
#[ORM\Table(name: 'quiz_attempt')]
#[ORM\Index(columns: ['session_id', 'created_at'], name: 'idx_quiz_attempt_session')]
class QuizAttempt
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private string $id;

    #[ORM\Column(length: 36)]
    private string $sessionId;

    #[ORM\Column(type: 'text')]
    private string $documentSource;

    /**
     * @var list<Question>
     */
    #[ORM\Column(type: 'json')]
    private array $questions;

    /**
     * @var list<Answer>
     */
    #[ORM\Column(type: 'json')]
    private array $answers = [];

    #[ORM\Column(nullable: true)]
    private ?int $score = null;

    #[ORM\Column]
    private int $totalQuestions;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /**
     * @param list<Question> $questions
     */
    public function __construct(string $sessionId, string $documentSource, array $questions)
    {
        if ([] === $questions) {
            throw new \InvalidArgumentException('A quiz attempt requires at least one question.');
        }

        $this->id = Uuid::v4()->toRfc4122();
        $this->sessionId = $sessionId;
        $this->documentSource = $documentSource;
        $this->questions = $questions;
        $this->totalQuestions = \count($questions);
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Records the visitor's answer to one question and returns whether it was
     * correct. A multi-choice question is correct only when the selected
     * indices are exactly the set of correct indices (no more, no less).
     *
     * @param list<int> $selectedIndices
     */
    public function recordAnswer(int $questionIndex, array $selectedIndices): bool
    {
        if (!isset($this->questions[$questionIndex])) {
            throw new \OutOfRangeException(\sprintf('No question at index %d.', $questionIndex));
        }

        $correctIndices = $this->questions[$questionIndex]['correctIndices'];
        $correct = [] === array_diff($correctIndices, $selectedIndices)
            && [] === array_diff($selectedIndices, $correctIndices);

        $this->answers[] = [
            'questionIndex' => $questionIndex,
            'selectedIndices' => array_values($selectedIndices),
            'correct' => $correct,
        ];

        return $correct;
    }

    public function isComplete(): bool
    {
        return \count($this->answers) >= $this->totalQuestions;
    }

    /**
     * Computes the final score from the recorded answers and marks the
     * attempt as completed. Idempotent: calling it again recomputes the same
     * result rather than double-counting.
     */
    public function complete(): void
    {
        $this->score = \count(array_filter($this->answers, static fn (array $answer): bool => $answer['correct']));
        $this->completedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getDocumentSource(): string
    {
        return $this->documentSource;
    }

    /**
     * @return list<Question>
     */
    public function getQuestions(): array
    {
        return $this->questions;
    }

    /**
     * @return list<Answer>
     */
    public function getAnswers(): array
    {
        return $this->answers;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function getTotalQuestions(): int
    {
        return $this->totalQuestions;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }
}
