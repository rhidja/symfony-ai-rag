<?php

namespace App\Service\Rag\Model;

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
