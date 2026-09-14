<?php

namespace App\Controller\Rag;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class QuizUiController
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    #[Route('/quiz', name: 'app_quiz_ui', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response($this->twig->render('rag/quiz.html.twig'));
    }
}
