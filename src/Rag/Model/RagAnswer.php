<?php

namespace App\Rag\Model;

final class RagAnswer
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
