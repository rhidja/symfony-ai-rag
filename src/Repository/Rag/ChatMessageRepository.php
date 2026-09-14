<?php

namespace App\Repository\Rag;

use App\Entity\Rag\ChatMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatMessage>
 */
class ChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    /**
     * @return list<ChatMessage>
     */
    public function findBySession(string $sessionId): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.sessionId = :sessionId')
            ->setParameter('sessionId', $sessionId)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function deleteBySession(string $sessionId): void
    {
        $this->createQueryBuilder('m')
            ->delete()
            ->andWhere('m.sessionId = :sessionId')
            ->setParameter('sessionId', $sessionId)
            ->getQuery()
            ->execute();
    }
}
