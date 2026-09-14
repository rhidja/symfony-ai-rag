<?php

namespace App\Controller\Rag;

use App\Service\Rag\Chat\ChatHistoryService;
use App\Service\Rag\Dto\AskRequest;
use App\Service\Rag\Dto\AskResponse;
use App\Service\Rag\RagAgentQueryService;
use App\Service\Rag\Session\VisitorSessionResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RagAskController
{
    public function __construct(
        private readonly RagAgentQueryService $ragQueryService,
        private readonly SerializerInterface&NormalizerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly ChatHistoryService $chatHistory,
        private readonly VisitorSessionResolver $chatSession,
    ) {
    }

    #[Route('/api/rag/ask', name: 'app_rag_ask', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            /** @var AskRequest $askRequest */
            $askRequest = $this->serializer->deserialize($request->getContent(), AskRequest::class, 'json');
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($askRequest);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = $violation->getMessage();
            }

            return new JsonResponse(['error' => 'Validation failed.', 'details' => $errors], Response::HTTP_BAD_REQUEST);
        }

        $answer = $this->ragQueryService->ask($askRequest->question);

        $this->chatHistory->record(
            $this->chatSession->resolve(),
            $askRequest->question,
            $answer->answer,
            $answer->sources,
        );

        $response = new AskResponse($answer->answer, $answer->sources);

        return new JsonResponse(
            $this->serializer->normalize($response, 'json'),
        );
    }
}
