<?php
declare(strict_types=1);

namespace Veyra\AI\Tool;

final class ToolContext
{
    /** @param array<int, string> $capabilities @param array<string, string> $featureStates */
    public function __construct(
        public readonly string $actorType,
        public readonly string $actorId,
        public readonly ?int $userId,
        public readonly ?string $guestSessionId,
        public readonly string $conversationId,
        public readonly array $capabilities,
        public readonly array $featureStates,
        public readonly string $locale,
        public readonly string $correlationId
    ) {
        if (!in_array($actorType, ['guest', 'customer', 'support', 'reviewer', 'manager', 'administrator'], true)) {
            throw new \InvalidArgumentException('Invalid actor type.');
        }
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function featureIsAvailable(string $feature): bool
    {
        return in_array($this->featureStates[$feature] ?? 'Off', ['On', 'Degraded'], true);
    }
}
