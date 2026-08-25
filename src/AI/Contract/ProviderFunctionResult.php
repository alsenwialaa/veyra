<?php
declare(strict_types=1);

namespace Veyra\AI\Contract;

use Veyra\AI\Provider\ProviderSafeToolResultProjector;

final class ProviderFunctionResult
{
    /** @param array<string, mixed> $result */
    private function __construct(
        public readonly string $callId,
        public readonly string $toolName,
        public readonly array $result
    ) {
        if ($callId === ''
            || !preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $toolName)
            || ($result['call_id'] ?? null) !== $callId
            || ($result['tool'] ?? null) !== $toolName
        ) {
            throw new \InvalidArgumentException('Invalid provider function result envelope.');
        }
    }

    /** @param array<string, mixed> $projectedResult */
    public static function fromProjected(array $projectedResult): self
    {
        if (!(new ProviderSafeToolResultProjector())->validateProjectedList([$projectedResult])) {
            throw new \InvalidArgumentException('Provider function result must be an exact provider-safe projection.');
        }

        return new self(
            (string) $projectedResult['call_id'],
            (string) $projectedResult['tool'],
            $projectedResult
        );
    }
}
