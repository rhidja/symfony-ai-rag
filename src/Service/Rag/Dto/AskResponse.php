<?php

namespace App\Service\Rag\Dto;

final class AskResponse
{
    /**
     * @param list<string> $sources
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $sources,
    ) {
    }
}
