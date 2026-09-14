<?php

namespace App\Twig\Components\Rag;

use App\Entity\Rag\QuizAttempt;
use App\Service\Rag\Quiz\Exception\QuizGenerationException;
use App\Service\Rag\Quiz\QuizAttemptService;
use App\Service\Rag\Session\VisitorSessionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Drives the whole quiz cycle: document selection, one question at a time
 * with immediate feedback, final recap, and the session's past attempts.
 *
 * The current question's correct answers/explanation are never exposed to
 * the client before it's answered: only `attemptId` and the running state
 * are LiveProps (dehydrated into the page), the attempt itself (including
 * its answer key) is re-loaded server-side on every action/render via
 * getAttempt().
 */
#[AsLiveComponent]
final class QuizComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $documentSource = '';

    #[LiveProp]
    public ?string $attemptId = null;

    /**
     * @var list<int>
     */
    #[LiveProp(writable: true)]
    public array $selectedIndices = [];

    /**
     * @var array{correct: bool, correctIndices: list<int>, explanation: string, source: string}|null
     */
    #[LiveProp]
    public ?array $feedback = null;

    #[LiveProp]
    public ?string $error = null;

    public function __construct(
        private readonly QuizAttemptService $quizAttemptService,
        private readonly VisitorSessionResolver $sessionResolver,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[LiveAction]
    public function start(): void
    {
        $this->error = null;

        if ('' === trim($this->documentSource)) {
            $this->error = 'Choisissez un document pour démarrer le quiz.';

            return;
        }

        try {
            $attempt = $this->quizAttemptService->start($this->sessionResolver->resolve(), $this->documentSource);
        } catch (QuizGenerationException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->attemptId = $attempt->getId();
        $this->feedback = null;
        $this->selectedIndices = [];
    }

    /**
     * Used for single-answer questions (a radio-like choice): selects
     * exactly one option, replacing any previous selection.
     */
    #[LiveAction]
    public function selectOption(#[LiveArg] int $index): void
    {
        $this->selectedIndices = [$index];
    }

    #[LiveAction]
    public function submitAnswer(): void
    {
        $attempt = $this->getAttempt();
        if (null === $attempt || $attempt->isComplete()) {
            return;
        }

        if ([] === $this->selectedIndices) {
            $this->error = 'Sélectionnez au moins une réponse.';

            return;
        }

        $this->error = null;
        $questionIndex = \count($attempt->getAnswers());

        $attempt = $this->quizAttemptService->answer($attempt->getId(), $questionIndex, $this->selectedIndices);

        $question = $attempt->getQuestions()[$questionIndex];
        $recordedAnswer = $attempt->getAnswers()[$questionIndex];

        $this->feedback = [
            'correct' => $recordedAnswer['correct'],
            'correctIndices' => $question['correctIndices'],
            'explanation' => $question['explanation'],
            'source' => $question['source'],
        ];
        $this->selectedIndices = [];
    }

    #[LiveAction]
    public function next(): void
    {
        $this->feedback = null;
        $this->selectedIndices = [];
    }

    #[LiveAction]
    public function restart(): void
    {
        $this->documentSource = '';
        $this->attemptId = null;
        $this->feedback = null;
        $this->selectedIndices = [];
        $this->error = null;
    }

    public function getAttempt(): ?QuizAttempt
    {
        if (null === $this->attemptId) {
            return null;
        }

        return $this->entityManager->getRepository(QuizAttempt::class)->find($this->attemptId);
    }

    /**
     * The question currently awaiting an answer, without its answer key —
     * null once the attempt is complete.
     *
     * @return array{index: int, prompt: string, options: list<string>, multiple: bool}|null
     */
    public function getCurrentQuestion(): ?array
    {
        $attempt = $this->getAttempt();
        if (null === $attempt || $attempt->isComplete()) {
            return null;
        }

        $index = \count($attempt->getAnswers());
        $question = $attempt->getQuestions()[$index];

        return [
            'index' => $index,
            'prompt' => $question['prompt'],
            'options' => $question['options'],
            'multiple' => $question['multiple'],
        ];
    }

    /**
     * @return list<array{source: string, title: ?string}>
     */
    public function getAvailableDocuments(): array
    {
        return $this->quizAttemptService->listAvailableDocuments();
    }

    /**
     * @return list<QuizAttempt>
     */
    public function getHistory(): array
    {
        return $this->quizAttemptService->getHistory($this->sessionResolver->resolve());
    }
}
