<?php
declare(strict_types=1);

namespace Veyra\Conversation\Domain;

final class PendingQuestion
{
    /**
     * @param array<string, mixed> $answerSchema
     * @param array<int, string>   $allowedChoiceIds
     * @param array<string, string> $focusedResources
     * @param array<string, int|string> $dependencyVersions
     */
    public function __construct(
        public readonly string $id,
        public readonly string $journeyId,
        public readonly string $stepId,
        public readonly string $messageId,
        public readonly array $answerSchema,
        public readonly array $allowedChoiceIds,
        public readonly array $focusedResources,
        public readonly string $sensitivity,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly array $dependencyVersions,
        public readonly ?string $invalidationReason = null,
        public readonly ?string $answeredBindingId = null,
        public readonly int $version = 1
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,35}$/D', $journeyId) !== 1) {
            throw new \InvalidArgumentException('Pending-question journey id is invalid.');
        }
        if (!in_array($sensitivity, ['informational', 'state_changing', 'confirmation_sensitive'], true)) {
            throw new \InvalidArgumentException('Invalid pending-question sensitivity.');
        }
        if ($expiresAt <= $createdAt) {
            throw new \InvalidArgumentException('Pending-question expiry is invalid.');
        }
        if ($version < 1) {
            throw new \InvalidArgumentException('Pending-question version is invalid.');
        }
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        return $this->invalidationReason === null && $this->answeredBindingId === null && $now < $this->expiresAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'journey_id' => $this->journeyId,
            'step_id' => $this->stepId,
            'message_id' => $this->messageId,
            'answer_schema' => $this->answerSchema,
            'allowed_choice_ids' => $this->allowedChoiceIds,
            'focused_resources' => $this->focusedResources,
            'sensitivity' => $this->sensitivity,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
            'dependency_versions' => $this->dependencyVersions,
            'invalidation_reason' => $this->invalidationReason,
            'answered_binding_id' => $this->answeredBindingId,
            'version' => $this->version,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['journey_id'],
            (string) $data['step_id'],
            (string) $data['message_id'],
            is_array($data['answer_schema'] ?? null) ? $data['answer_schema'] : [],
            is_array($data['allowed_choice_ids'] ?? null) ? array_values(array_filter($data['allowed_choice_ids'], 'is_string')) : [],
            is_array($data['focused_resources'] ?? null) ? $data['focused_resources'] : [],
            (string) $data['sensitivity'],
            new \DateTimeImmutable((string) $data['created_at']),
            new \DateTimeImmutable((string) $data['expires_at']),
            is_array($data['dependency_versions'] ?? null) ? $data['dependency_versions'] : [],
            isset($data['invalidation_reason']) ? (string) $data['invalidation_reason'] : null,
            isset($data['answered_binding_id']) ? (string) $data['answered_binding_id'] : null,
            max(1, (int) ($data['version'] ?? 1))
        );
    }
}
