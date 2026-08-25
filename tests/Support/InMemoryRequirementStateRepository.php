<?php

declare(strict_types=1);

namespace Veyra\Tests\Support;

use Veyra\Requirements\Contract\RequirementStateRepository;
use Veyra\Requirements\Domain\RequirementState;

/** Deterministic actor-scoped CAS fixture for requirement-state tests only. */
final class InMemoryRequirementStateRepository implements RequirementStateRepository
{
    /** @var array<string, RequirementState> */
    private array $states = [];

    /** @var array<string, true> */
    private array $corruptLoads = [];

    /** @var array<string, RequirementState> */
    private array $emptyInsertRaceWinners = [];

    private int $successfulCompareAndSwaps = 0;

    public function loadOwned(string $conversationId, string $actorType, string $actorId): ?RequirementState
    {
        $key = $this->key($conversationId, $actorType, $actorId);
        if (isset($this->corruptLoads[$key])) {
            throw new \UnexpectedValueException('Stored requirement aggregate is invalid.');
        }

        return $this->states[$key] ?? null;
    }

    public function compareAndSwap(RequirementState $expected, RequirementState $next): bool
    {
        if ($expected->conversationId !== $next->conversationId
            || $expected->actorType !== $next->actorType
            || $expected->actorId !== $next->actorId
            || $next->resourceVersion !== $expected->resourceVersion + 1
        ) {
            return false;
        }

        $key = $this->key($expected->conversationId, $expected->actorType, $expected->actorId);
        if ($expected->resourceVersion === 0
            && !isset($this->states[$key])
            && isset($this->emptyInsertRaceWinners[$key])
        ) {
            $winner = $this->emptyInsertRaceWinners[$key];
            unset($this->emptyInsertRaceWinners[$key]);
            if ($winner->conversationId !== $expected->conversationId
                || $winner->actorType !== $expected->actorType
                || $winner->actorId !== $expected->actorId
                || $winner->resourceVersion !== 1
            ) {
                throw new \LogicException('Configured requirement-state race winner is invalid.');
            }
            $this->states[$key] = $winner;
            ++$this->successfulCompareAndSwaps;

            return false;
        }

        $current = $this->states[$key] ?? null;
        if ($current === null) {
            if ($expected->resourceVersion !== 0) {
                return false;
            }
        } elseif ($current->resourceVersion !== $expected->resourceVersion
            || !hash_equals($current->stateHash, $expected->stateHash)
        ) {
            return false;
        }

        $this->states[$key] = $next;
        ++$this->successfulCompareAndSwaps;

        return true;
    }

    public function seed(RequirementState $state): void
    {
        $this->states[$this->key($state->conversationId, $state->actorType, $state->actorId)] = $state;
    }

    public function corrupt(string $conversationId, string $actorType, string $actorId): void
    {
        $this->corruptLoads[$this->key($conversationId, $actorType, $actorId)] = true;
    }

    /** Simulates another request winning the first actor-owned head insert. */
    public function loseNextEmptyInsertTo(RequirementState $winner): void
    {
        $key = $this->key($winner->conversationId, $winner->actorType, $winner->actorId);
        $this->emptyInsertRaceWinners[$key] = $winner;
    }

    public function successfulCompareAndSwaps(): int
    {
        return $this->successfulCompareAndSwaps;
    }

    private function key(string $conversationId, string $actorType, string $actorId): string
    {
        return hash('sha256', $actorType . "\0" . $actorId . "\0" . $conversationId);
    }
}
