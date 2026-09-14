<?php

namespace App\Tests\Unit\Entity\Rag;

use App\Entity\Rag\QuizAttempt;
use PHPUnit\Framework\TestCase;

final class QuizAttemptTest extends TestCase
{
    private function createAttempt(): QuizAttempt
    {
        return new QuizAttempt('session-1', '/docs/manual.pdf', [
            [
                'prompt' => 'What color is the sky?',
                'options' => ['Blue', 'Green', 'Red'],
                'correctIndices' => [0],
                'multiple' => false,
                'explanation' => 'The sky appears blue due to Rayleigh scattering.',
                'source' => '/docs/manual.pdf',
            ],
            [
                'prompt' => 'Which of these are primary colors?',
                'options' => ['Red', 'Green', 'Blue', 'Orange'],
                'correctIndices' => [0, 2],
                'multiple' => true,
                'explanation' => 'Red and blue are primary colors in this context.',
                'source' => '/docs/manual.pdf',
            ],
        ]);
    }

    public function testConstructorRejectsEmptyQuestionList(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new QuizAttempt('session-1', '/docs/manual.pdf', []);
    }

    public function testConstructorDerivesTotalQuestionsFromQuestionCount(): void
    {
        $attempt = $this->createAttempt();

        self::assertSame(2, $attempt->getTotalQuestions());
        self::assertFalse($attempt->isComplete());
    }

    public function testRecordAnswerReturnsTrueForCorrectSingleChoiceAnswer(): void
    {
        $attempt = $this->createAttempt();

        self::assertTrue($attempt->recordAnswer(0, [0]));
        self::assertSame(
            ['questionIndex' => 0, 'selectedIndices' => [0], 'correct' => true],
            $attempt->getAnswers()[0],
        );
    }

    public function testRecordAnswerReturnsFalseForIncorrectSingleChoiceAnswer(): void
    {
        $attempt = $this->createAttempt();

        self::assertFalse($attempt->recordAnswer(0, [1]));
    }

    public function testRecordAnswerRequiresExactSetForMultiChoiceQuestion(): void
    {
        $attempt = $this->createAttempt();

        // Correct set, order-independent.
        self::assertTrue($attempt->recordAnswer(1, [2, 0]));
    }

    public function testRecordAnswerRejectsPartialSelectionForMultiChoiceQuestion(): void
    {
        $attempt = $this->createAttempt();

        self::assertFalse($attempt->recordAnswer(1, [0]));
    }

    public function testRecordAnswerRejectsOverSelectionForMultiChoiceQuestion(): void
    {
        $attempt = $this->createAttempt();

        self::assertFalse($attempt->recordAnswer(1, [0, 2, 3]));
    }

    public function testRecordAnswerThrowsForOutOfRangeQuestionIndex(): void
    {
        $attempt = $this->createAttempt();

        $this->expectException(\OutOfRangeException::class);

        $attempt->recordAnswer(5, [0]);
    }

    public function testIsCompleteAfterAllQuestionsAnswered(): void
    {
        $attempt = $this->createAttempt();

        $attempt->recordAnswer(0, [0]);
        self::assertFalse($attempt->isComplete());

        $attempt->recordAnswer(1, [0, 2]);
        self::assertTrue($attempt->isComplete());
    }

    public function testCompleteComputesScoreFromAnswers(): void
    {
        $attempt = $this->createAttempt();

        $attempt->recordAnswer(0, [0]); // correct
        $attempt->recordAnswer(1, [0]); // incorrect (partial)
        $attempt->complete();

        self::assertSame(1, $attempt->getScore());
        self::assertNotNull($attempt->getCompletedAt());
    }
}
