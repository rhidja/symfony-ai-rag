<?php

namespace App\Service\Rag\Chat;

use App\Entity\Rag\ChatMessage;
use App\Repository\Rag\ChatMessageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Records and retrieves the question/answer history of a chat session.
 */
final class ChatHistoryService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ChatMessageRepository $repository,
    ) {
    }

    /**
     * @return list<ChatMessage>
     */
    public function getHistory(string $sessionId): array
    {
        return $this->repository->findBySession($sessionId);
    }

    /**
     * @param list<string> $sources
     */
    public function record(string $sessionId, string $question, string $answer, array $sources): ChatMessage
    {
        $message = new ChatMessage($sessionId, $question, $answer, $sources);

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        return $message;
    }

    public function clear(string $sessionId): void
    {
        $this->repository->deleteBySession($sessionId);
    }
}
