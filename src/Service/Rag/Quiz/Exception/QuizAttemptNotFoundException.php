<?php

namespace App\Service\Rag\Quiz\Exception;

final class QuizAttemptNotFoundException extends \RuntimeException
{
    public static function withId(string $attemptId): self
    {
        return new self(\sprintf('No quiz attempt found with id "%s".', $attemptId));
    }
}
