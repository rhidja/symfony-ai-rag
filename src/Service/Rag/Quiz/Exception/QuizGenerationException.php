<?php

namespace App\Service\Rag\Quiz\Exception;

final class QuizGenerationException extends \RuntimeException
{
    public static function documentNotFound(string $documentSource): self
    {
        return new self(\sprintf('No indexed content found for document "%s".', $documentSource));
    }

    public static function invalidModelOutput(string $reason): self
    {
        return new self(\sprintf('The model produced an unusable quiz: %s', $reason));
    }
}
