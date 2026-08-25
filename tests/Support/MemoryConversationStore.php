<?php

declare(strict_types=1);

namespace Veyra\Tests\Support;

use Veyra\Conversation\Application\ConversationStore;
use Veyra\Conversation\Domain\ConversationFocus;

final class MemoryConversationStore implements ConversationStore
{
    /** @var array<string, mixed> */
    private array $memory = [];

    public function __construct(private readonly string $sourceMessageId, private readonly string $sourceText)
    {
    }

    public function createConversation(string $actorType, string $actorId, ?int $userId, ?string $guestSessionId): string
    {
        throw new \LogicException('Not used.');
    }

    public function currentOwnedConversation(string $actorType, string $actorId): ?array { return []; }
    public function getOwnedConversation(string $conversationId, string $actorType, string $actorId): ?array { return []; }
    public function recentVisibleMessages(string $conversationId, string $actorType, string $actorId, int $limit): array { return []; }

    public function visibleMessage(string $conversationId, string $actorType, string $actorId, string $messageId): ?array
    {
        return hash_equals($this->sourceMessageId, $messageId)
            ? ['message_id' => $messageId, 'sender_type' => 'customer', 'content' => ['text' => $this->sourceText]]
            : null;
    }

    public function journeys(string $conversationId, string $actorType, string $actorId): array { return []; }
    public function focus(string $conversationId, string $actorType, string $actorId): ?ConversationFocus { return null; }
    public function memory(string $conversationId, string $actorType, string $actorId): array { return $this->memory; }
    public function summary(string $conversationId, string $actorType, string $actorId): array { return []; }

    public function appendVisibleMessage(string $conversationId, string $actorType, string $actorId, string $senderType, string $text, array $renderPayload, array $evidence, string $correlationId): string
    {
        throw new \LogicException('Not used.');
    }

    public function saveFocus(string $conversationId, string $actorType, string $actorId, ConversationFocus $focus, string $expectedVersion): bool
    {
        return false;
    }

    public function consumePendingQuestion(string $conversationId, string $actorType, string $actorId, string $questionId, string $expectedFocusVersion, int $expectedQuestionVersion, string $customerMessageId, array $validatedBinding): array
    {
        return ['consumed' => false, 'code' => 'pending_question_unavailable', 'binding_id' => null];
    }

    public function saveMemory(string $conversationId, string $actorType, string $actorId, array $memory, string $sourceMessageId): bool
    {
        if (!hash_equals($this->sourceMessageId, $sourceMessageId)) {
            return false;
        }
        $this->memory = $memory;
        return true;
    }
}
