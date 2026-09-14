<?php

namespace App\Service\Rag\Session;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves a stable, anonymous session id for the current visitor, backed by
 * the Symfony session (cookie-based, no user account involved). Shared by
 * every feature that needs to group activity per visitor (chat history, quiz
 * attempts, ...).
 */
final class VisitorSessionResolver
{
    private const SESSION_KEY = 'rag_chat_session_id';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function resolve(): string
    {
        $session = $this->requestStack->getSession();
        $sessionId = $session->get(self::SESSION_KEY);

        if (!\is_string($sessionId) || '' === $sessionId) {
            $sessionId = Uuid::v4()->toRfc4122();
            $session->set(self::SESSION_KEY, $sessionId);
        }

        return $sessionId;
    }
}
