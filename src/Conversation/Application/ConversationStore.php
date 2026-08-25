<?php
declare(strict_types=1);

namespace Veyra\Conversation\Application;

use Veyra\Conversation\Domain\ConversationFocus;
use Veyra\Conversation\Domain\JourneyState;

interface ConversationStore
{
    public function createConversation(string $actorType, string $actorId, ?int $userId, ?string $guestSessionId): string;

    /** @return array<string, mixed>|null */
    public function currentOwnedConversation(string $actorType, string $actorId): ?array;

    /** @return array<string, mixed>|null */
    public function getOwnedConversation(string $conversationId, string $actorType, string $actorId): ?array;

    /** @return array<int, array<string, mixed>> */
    public function recentVisibleMessages(string $conversationId, string $actorType, string $actorId, int $limit): array;

    /** @return array<string, mixed>|null */
    public function visibleMessage(string $conversationId, string $actorType, string $actorId, string $messageId): ?array;

    /** @return array<int, JourneyState> */
    public function journeys(string $conversationId, string $actorType, string $actorId): array;

    public function focus(string $conversationId, string $actorType, string $actorId): ?ConversationFocus;

    /** @return array<string, mixed> */
    public function memory(string $conversationId, string $actorType, string $actorId): array;

    /** @return array<string, mixed> */
    public function summary(string $conversationId, string $actorType, string $actorId): array;

    /** @param array<string, mixed> $renderPayload @param array<int, array<string, mixed>> $evidence */
    public function appendVisibleMessage(
        string $conversationId,
        string $actorType,
        string $actorId,
        string $senderType,
        string $text,
        array $renderPayload,
        array $evidence,
        string $correlationId
    ): string;

    public function saveFocus(string $conversationId, string $actorType, string $actorId, ConversationFocus $focus, string $expectedVersion): bool;

    /**
     * Atomically consumes one exact active Pending Question and advances the
     * Conversation Focus compare-and-set version. The validated binding is a
     * bounded audit record; it never supplies identity or permission.
     *
     * @param array<string, mixed> $validatedBinding
     * @return array{consumed:bool,code:string,binding_id:?string}
     */
    public function consumePendingQuestion(
        string $conversationId,
        string $actorType,
        string $actorId,
        string $questionId,
        string $expectedFocusVersion,
        int $expectedQuestionVersion,
        string $customerMessageId,
        array $validatedBinding
    ): array;

    /** @param array<string, mixed> $memory */
    public function saveMemory(string $conversationId, string $actorType, string $actorId, array $memory, string $sourceMessageId): bool;
}
