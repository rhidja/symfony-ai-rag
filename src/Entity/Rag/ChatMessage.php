<?php

namespace App\Entity\Rag;

use App\Repository\Rag\ChatMessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One question/answer exchange in a chat session's history.
 */
#[ORM\Entity(repositoryClass: ChatMessageRepository::class)]
#[ORM\Table(name: 'chat_message')]
#[ORM\Index(columns: ['session_id', 'created_at'], name: 'idx_chat_message_session')]
class ChatMessage
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private string $id;

    #[ORM\Column(length: 36)]
    private string $sessionId;

    #[ORM\Column(type: 'text')]
    private string $question;

    #[ORM\Column(type: 'text')]
    private string $answer;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $sources;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $sources
     */
    public function __construct(string $sessionId, string $question, string $answer, array $sources)
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->sessionId = $sessionId;
        $this->question = $question;
        $this->answer = $answer;
        $this->sources = $sources;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function getAnswer(): string
    {
        return $this->answer;
    }

    /**
     * @return list<string>
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
