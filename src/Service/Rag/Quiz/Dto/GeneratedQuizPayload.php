<?php

namespace App\Service\Rag\Quiz\Dto;

/**
 * Root object the model's structured output is deserialized into (a bare
 * JSON list isn't a valid root for the platform's response_format schema).
 */
final class GeneratedQuizPayload
{
    /**
     * @param GeneratedQuestion[] $questions
     */
    public function __construct(
        public readonly array $questions,
    ) {
    }
}
