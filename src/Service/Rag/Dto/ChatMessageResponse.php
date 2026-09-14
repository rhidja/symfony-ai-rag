<?php

namespace App\Service\Rag\Dto;

final class ChatMessageResponse
{
    /**
     * @param list<string> $sources
     */
    public function __construct(
        public readonly string $question,
        public readonly string $answer,
        public readonly array $sources,
        public readonly string $createdAt,
    ) {
    }
}
