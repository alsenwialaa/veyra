<?php
declare(strict_types=1);

namespace Veyra\Conversation\Domain;

final class JourneyState
{
    /**
     * @param array<string, mixed> $fields
     * @param array<int, string> $openQuestionIds
     * @param array<string, string> $relatedResources
     * @param array<string, int|string> $dependencyVersions
     */
    public function __construct(
        public readonly string $id,
        public readonly string $conversationId,
        public readonly string $actorId,
        public readonly string $type,
        public readonly string $version,
        public readonly string $status,
        public readonly string $currentStep,
        public readonly string $resumeStep,
        public readonly array $fields,
        public readonly array $openQuestionIds,
        public readonly array $relatedResources,
        public readonly array $dependencyVersions,
        public readonly ?string $lastVerifiedCheckpoint
    ) {
        if (!in_array($status, ['active', 'paused', 'completed', 'cancelled', 'blocked'], true)) {
            throw new \InvalidArgumentException('Invalid journey status.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
