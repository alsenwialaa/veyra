<?php
declare(strict_types=1);

namespace Veyra\Conversation\Domain;

final class ConversationFocus
{
    /** @param array<string, string> $focusedResources @param array<int, string> $unresolvedReferences */
    public function __construct(
        public readonly string $version,
        public readonly ?string $foregroundJourneyId,
        public readonly array $focusedResources,
        public readonly ?PendingQuestion $pendingQuestion,
        public readonly array $unresolvedReferences,
        public readonly string $sourceMessageId,
        public readonly \DateTimeImmutable $updatedAt
    ) {
        if ($foregroundJourneyId !== null
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,35}$/D', $foregroundJourneyId) !== 1
        ) {
            throw new \InvalidArgumentException('Foreground journey id is invalid.');
        }
        if ($pendingQuestion !== null
            && ($foregroundJourneyId === null || $pendingQuestion->journeyId !== $foregroundJourneyId)
        ) {
            throw new \InvalidArgumentException('Pending question is not bound to the foreground journey.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'foreground_journey_id' => $this->foregroundJourneyId,
            'focused_resources' => $this->focusedResources,
            'pending_question' => $this->pendingQuestion?->toArray(),
            'unresolved_references' => $this->unresolvedReferences,
            'source_message_id' => $this->sourceMessageId,
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['version'] ?? '0'),
            isset($data['foreground_journey_id']) ? (string) $data['foreground_journey_id'] : null,
            is_array($data['focused_resources'] ?? null) ? $data['focused_resources'] : [],
            is_array($data['pending_question'] ?? null) ? PendingQuestion::fromArray($data['pending_question']) : null,
            is_array($data['unresolved_references'] ?? null) ? array_values(array_filter($data['unresolved_references'], 'is_string')) : [],
            (string) ($data['source_message_id'] ?? ''),
            new \DateTimeImmutable((string) ($data['updated_at'] ?? 'now'))
        );
    }
}
