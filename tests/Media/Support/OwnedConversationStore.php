<?php

declare(strict_types=1);

namespace Veyra\Tests\Media\Support;

use Veyra\Conversation\Application\ConversationStore;
use Veyra\Conversation\Domain\ConversationFocus;

final class OwnedConversationStore implements ConversationStore
{
    public function __construct(
        private readonly string $conversationId,
        private readonly string $actorType,
        private readonly string $actorId
    ) {
    }

    public function createConversation(string $actorType, string $actorId, ?int $userId, ?string $guestSessionId): string
    {
        throw new \LogicException('Not used by this fixture.');
    }

    public function currentOwnedConversation(string $actorType, string $actorId): ?array
    {
        return $this->owned($this->conversationId, $actorType, $actorId) ? ['conversation_id' => $this->conversationId] : null;
    }

    public function getOwnedConversation(string $conversationId, string $actorType, string $actorId): ?array
    {
        return $this->owned($conversationId, $actorType, $actorId) ? ['conversation_id' => $conversationId] : null;
    }

    public function recentVisibleMessages(string $conversationId, string $actorType, string $actorId, int $limit): array
    {
        return [];
    }

    public function visibleMessage(string $conversationId, string $actorType, string $actorId, string $messageId): ?array
    {
        return $this->owned($conversationId, $actorType, $actorId) && preg_match('/^msg_[a-f0-9]{32}$/D', $messageId) === 1
            ? ['message_id' => $messageId]
            : null;
    }

    public function journeys(string $conversationId, string $actorType, string $actorId): array
    {
        return [];
    }

    public function focus(string $conversationId, string $actorType, string $actorId): ?ConversationFocus
    {
        return null;
    }

    public function memory(string $conversationId, string $actorType, string $actorId): array
    {
        return [];
    }

    public function summary(string $conversationId, string $actorType, string $actorId): array
    {
        return [];
    }

    public function appendVisibleMessage(
        string $conversationId,
        string $actorType,
        string $actorId,
        string $senderType,
        string $text,
        array $renderPayload,
        array $evidence,
        string $correlationId
    ): string {
        throw new \LogicException('Not used by this fixture.');
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
        return false;
    }

    private function owned(string $conversationId, string $actorType, string $actorId): bool
    {
        return hash_equals($this->conversationId, $conversationId)
            && hash_equals($this->actorType, $actorType)
            && hash_equals($this->actorId, $actorId);
    }
}
