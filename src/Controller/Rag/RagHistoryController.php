<?php

namespace App\Controller\Rag;

use App\Service\Rag\Chat\ChatHistoryService;
use App\Service\Rag\Chat\ChatSessionResolver;
use App\Service\Rag\Dto\ChatMessageResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Exposes the current visitor's chat session history (one continuous
 * conversation per session, see ChatSessionResolver).
 */
final class RagHistoryController
{
    public function __construct(
        private readonly ChatHistoryService $chatHistory,
        private readonly ChatSessionResolver $chatSession,
        private readonly NormalizerInterface $serializer,
    ) {
    }

    #[Route('/api/rag/history', name: 'app_rag_history_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $messages = array_map(
            static fn ($message): ChatMessageResponse => new ChatMessageResponse(
                $message->getQuestion(),
                $message->getAnswer(),
                $message->getSources(),
                $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ),
            $this->chatHistory->getHistory($this->chatSession->resolve()),
        );

        return new JsonResponse($this->serializer->normalize($messages, 'json'));
    }

    #[Route('/api/rag/history', name: 'app_rag_history_clear', methods: ['DELETE'])]
    public function clear(): Response
    {
        $this->chatHistory->clear($this->chatSession->resolve());

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
