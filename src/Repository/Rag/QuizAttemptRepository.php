<?php

namespace App\Repository\Rag;

use App\Entity\Rag\QuizAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizAttempt>
 */
class QuizAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizAttempt::class);
    }

    /**
     * @return list<QuizAttempt>
     */
    public function findBySession(string $sessionId): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.sessionId = :sessionId')
            ->setParameter('sessionId', $sessionId)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
