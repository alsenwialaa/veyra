<?php

declare(strict_types=1);

namespace Veyra\Infrastructure\Database\Migration;

/**
 * Builds a bounded, progress-aware automatic migration retry state.
 *
 * A schema-version advance proves useful work was completed and starts a new
 * retry budget. Repeated non-success results at the same schema version consume
 * the existing budget, including lock contention and migration failures.
 */
final class MigrationRetryPolicy
{
    public function __construct(private readonly int $maximumAttempts)
    {
        if ($maximumAttempts < 1 || $maximumAttempts > 100) {
            throw new \InvalidArgumentException('Migration retry bound is outside the safe range.');
        }
    }

    public function isValidPersistedState(mixed $state): bool
    {
        if (!is_array($state)) {
            return false;
        }

        return isset($state['attempts'], $state['schema_version'], $state['last_code'])
            && is_int($state['attempts'])
            && $state['attempts'] >= 1
            && $state['attempts'] <= $this->maximumAttempts
            && is_string($state['schema_version'])
            && $state['schema_version'] !== ''
            && is_string($state['last_code'])
            && preg_match('/^[a-z][a-z0-9_]{2,95}$/D', $state['last_code']) === 1;
    }

    /**
     * @param array<string,mixed>|null $previous
     * @return array{attempts:int,schema_version:string,last_code:string}
     */
    public function nextState(?array $previous, string $schemaVersion, string $resultCode): array
    {
        if ($schemaVersion === '' || preg_match('/^[0-9]+(?:\.[0-9]+){2}(?:[-+][A-Za-z0-9.-]+)?$/D', $schemaVersion) !== 1) {
            throw new \InvalidArgumentException('Migration retry schema version is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9_]{2,95}$/D', $resultCode) !== 1) {
            throw new \InvalidArgumentException('Migration retry result code is invalid.');
        }
        if ($previous !== null && !$this->isValidPersistedState($previous)) {
            throw new \InvalidArgumentException('Migration retry state is invalid.');
        }

        $attempts = 1;
        if ($previous !== null && hash_equals($previous['schema_version'], $schemaVersion)) {
            $attempts = min($this->maximumAttempts, $previous['attempts'] + 1);
        }

        return [
            'attempts' => $attempts,
            'schema_version' => $schemaVersion,
            'last_code' => $resultCode,
        ];
    }

    /** @param array{attempts:int,schema_version:string,last_code:string} $state */
    public function exhausted(array $state): bool
    {
        return $state['attempts'] >= $this->maximumAttempts;
    }
}
