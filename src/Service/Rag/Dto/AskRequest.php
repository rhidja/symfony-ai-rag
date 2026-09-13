<?php

namespace App\Service\Rag\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class AskRequest
{
    #[Assert\NotBlank(message: 'The question must not be empty.')]
    public string $question = '';
}
