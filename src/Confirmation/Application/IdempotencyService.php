<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Application;

use Veyra\Confirmation\Domain\IdempotencyDecision;
use Veyra\Confirmation\Domain\IdempotencyDecisionStatus;
use Veyra\Confirmation\Domain\IdempotencyRecord;
use Veyra\Identity\Domain\Actor;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Shared\Domain\Clock;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\SecretDigester;
use Veyra\Shared\Domain\StateHash;
use Veyra\Shared\Domain\Uuid;

final class IdempotencyService
{
    public function __construct(
        private readonly IdempotencyRepository $records,
        private readonly SecretDigester $digester,
        private readonly Clock $clock,
        private readonly int $lifetimeSeconds = 86400
    ) {
        if ($lifetimeSeconds < 300 || $lifetimeSeconds > 2592000) {
            throw new \InvalidArgumentException('Idempotency lifetime is outside the safe bound.');
        }
    }

    public function begin(
        Actor $actor,
        string $action,
        string $rawKey,
        mixed $payload,
        string $resourceScope,
        CorrelationId $correlationId
    ): IdempotencyDecision {
        $this->validateEnvelope($action, $rawKey, $resourceScope);
        $scope = ActorScope::fromActor($actor);
        $keyDigest = $this->digester->digest($rawKey, 'idempotency');
        $payloadHash = StateHash::fromPayload($payload);
        $resourceScopeHash = hash('sha256', $resourceScope);
        $now = $this->clock->now();
        $record = new IdempotencyRecord(
            Uuid::v4(),
            $keyDigest,
            $scope,
            $action,
            $resourceScopeHash,
            $payloadHash,
            'in_progress',
            null,
            null,
            false,
            $correlationId,
            1,
            $now->addSeconds($this->lifetimeSeconds),
            null,
            $now,
            $now
        );

        if ($this->records->insert($record)) {
            return new IdempotencyDecision(IdempotencyDecisionStatus::Claimed, 'idempotency_claimed', $record);
        }

        $existing = $this->records->find($scope, $action, $keyDigest);

        if ($existing === null) {
            throw new \RuntimeException('Idempotency claim failed without a recoverable record.');
        }

        return $this->classifyExisting($existing, $payloadHash, $resourceScopeHash);
    }

    /**
     * Inspect an existing actor-owned operation without creating a claim.
     *
     * This is used before current-state validation only to recover an already
     * claimed/terminal request. A missing record returns null so the caller can
     * perform fresh authorization, state validation, and atomic claim/confirm.
     */
    public function inspect(
        Actor $actor,
        string $action,
        string $rawKey,
        mixed $payload,
        string $resourceScope
    ): ?IdempotencyDecision {
        $this->validateEnvelope($action, $rawKey, $resourceScope);
        $scope = ActorScope::fromActor($actor);
        $existing = $this->records->find(
            $scope,
            $action,
            $this->digester->digest($rawKey, 'idempotency')
        );
        if (!$existing instanceof IdempotencyRecord) {
            return null;
        }

        return $this->classifyExisting(
            $existing,
            StateHash::fromPayload($payload),
            hash('sha256', $resourceScope)
        );
    }

    private function classifyExisting(
        IdempotencyRecord $existing,
        StateHash $payloadHash,
        string $resourceScopeHash
    ): IdempotencyDecision {

        if (!$existing->payloadHash->equals($payloadHash)
            || !hash_equals($existing->resourceScopeHash, $resourceScopeHash)
        ) {
            return new IdempotencyDecision(IdempotencyDecisionStatus::Conflict, 'idempotency_payload_conflict', $existing);
        }

        $now = $this->clock->now();
        if ($existing->status === 'in_progress') {
            $status = $existing->expiresAt->isAtOrBefore($now)
                ? IdempotencyDecisionStatus::ReconcileRequired
                : IdempotencyDecisionStatus::InProgress;
            $code = $status === IdempotencyDecisionStatus::ReconcileRequired
                ? 'idempotency_expired_reconciliation_required'
                : 'idempotency_in_progress';

            return new IdempotencyDecision($status, $code, $existing);
        }

        if ($existing->status === 'uncertain') {
            return new IdempotencyDecision(
                IdempotencyDecisionStatus::ReconcileRequired,
                'idempotency_uncertain_reconciliation_required',
                $existing
            );
        }

        return new IdempotencyDecision(IdempotencyDecisionStatus::Replay, 'idempotency_replay', $existing);
    }

    /** @param array<string, mixed> $result */
    public function complete(IdempotencyRecord $record, string $resultCode, array $result, bool $retrySafe = false): bool
    {
        return $this->transition($record, 'succeeded', $resultCode, $result, $retrySafe);
    }

    /** @param array<string, mixed> $result */
    public function fail(IdempotencyRecord $record, string $resultCode, array $result, bool $retrySafe): bool
    {
        return $this->transition($record, 'failed', $resultCode, $result, $retrySafe);
    }

    /** @param array<string, mixed> $knownState */
    public function markUncertain(IdempotencyRecord $record, string $resultCode, array $knownState): bool
    {
        return $this->transition($record, 'uncertain', $resultCode, $knownState, false);
    }

    /**
     * Inspect one actor-owned idempotency operation for a read-only
     * reconciliation refresh. The raw key is never returned or persisted.
     *
     * @return array{known:bool,complete:bool,status:string,code:string}
     */
    public function reconciliationStatus(
        Actor $actor,
        string $action,
        string $rawKey,
        string $resourceScope
    ): array {
        $this->validateEnvelope($action, $rawKey, $resourceScope);
        $scope = ActorScope::fromActor($actor);
        $record = $this->records->find(
            $scope,
            $action,
            $this->digester->digest($rawKey, 'idempotency')
        );
        if (!$record instanceof IdempotencyRecord
            || !hash_equals($record->resourceScopeHash, hash('sha256', $resourceScope))
        ) {
            return ['known' => false, 'complete' => false, 'status' => 'unknown', 'code' => 'reconciliation_record_unavailable'];
        }

        $complete = in_array($record->status, ['succeeded', 'failed'], true);
        return [
            'known' => true,
            'complete' => $complete,
            'status' => $record->status,
            'code' => $complete
                ? 'reconciliation_terminal'
                : 'reconciliation_required',
        ];
    }

    /** @param array<string, mixed> $result */
    private function transition(
        IdempotencyRecord $record,
        string $status,
        string $resultCode,
        array $result,
        bool $retrySafe
    ): bool {
        if (preg_match('/^[a-z][a-z0-9_]{2,95}$/D', $resultCode) !== 1) {
            throw new \InvalidArgumentException('Idempotency result code is invalid.');
        }

        return $this->records->complete(
            $record,
            $status,
            $resultCode,
            $result,
            $retrySafe,
            $this->clock->now()
        );
    }

    private function validateEnvelope(string $action, string $rawKey, string $resourceScope): void
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{2,190}$/D', $action) !== 1
            || strlen($rawKey) < 8
            || strlen($rawKey) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $rawKey) === 1
            || $resourceScope === ''
            || strlen($resourceScope) > 2048
        ) {
            throw new \InvalidArgumentException('Idempotency envelope is invalid.');
        }
    }
}
