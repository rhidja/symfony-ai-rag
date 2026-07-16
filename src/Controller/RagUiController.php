<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class RagUiController
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    #[Route('/', name: 'app_rag_ui', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response($this->twig->render('rag/index.html.twig'));
    }
}
