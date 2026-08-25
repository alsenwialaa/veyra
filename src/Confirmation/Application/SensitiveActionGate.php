<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Application;

use Veyra\Audit\Application\AuditWriter;
use Veyra\Confirmation\Domain\IdempotencyDecisionStatus;
use Veyra\Confirmation\Domain\SensitiveActionGateResult;
use Veyra\Confirmation\Domain\SensitiveActionLease;
use Veyra\Identity\Domain\Actor;
use Veyra\Infrastructure\Database\WpdbTransactionManager;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\StateHash;

final class SensitiveActionGate
{
    public function __construct(
        private readonly WpdbTransactionManager $transactions,
        private readonly ConfirmationService $confirmations,
        private readonly IdempotencyService $idempotency,
        private readonly AuditWriter $audit
    ) {
    }

    public function begin(
        Actor $actor,
        string $rawConfirmationToken,
        StateHash $currentState,
        string $action,
        string $rawIdempotencyKey,
        mixed $payload,
        string $resourceScope,
        CorrelationId $correlationId
    ): SensitiveActionGateResult {
        try {
            return $this->transactions->transactional(function () use (
                $actor,
                $rawConfirmationToken,
                $currentState,
                $action,
                $rawIdempotencyKey,
                $payload,
                $resourceScope,
                $correlationId
            ): SensitiveActionGateResult {
                $decision = $this->idempotency->begin(
                    $actor,
                    $action,
                    $rawIdempotencyKey,
                    $payload,
                    $resourceScope,
                    $correlationId
                );

                if ($decision->status !== IdempotencyDecisionStatus::Claimed) {
                    return SensitiveActionGateResult::fromIdempotency($decision);
                }

                $confirmation = $this->confirmations->consume(
                    $actor,
                    $rawConfirmationToken,
                    $currentState,
                    $correlationId
                );

                if (!$confirmation->consumed || $confirmation->record === null) {
                    throw new SensitiveActionRollback(
                        SensitiveActionGateResult::blocked($confirmation->code)
                    );
                }

                if (!hash_equals($confirmation->record->action, $action)
                    || !hash_equals($confirmation->record->idempotencyScope, $resourceScope)
                ) {
                    throw new SensitiveActionRollback(
                        SensitiveActionGateResult::blocked('confirmation_scope_mismatch')
                    );
                }

                $auditReference = $this->audit->writeRequired(
                    $actor,
                    'confirmation.consume',
                    'confirmation',
                    $confirmation->record->id->value(),
                    'confirmation_consumed',
                    $correlationId,
                    [
                        'action' => $action,
                        'state_hash' => $currentState->value(),
                        'idempotency_record_id' => $decision->record->id,
                    ]
                );

                return SensitiveActionGateResult::ready(
                    new SensitiveActionLease($confirmation->record, $decision->record, $auditReference)
                );
            });
        } catch (SensitiveActionRollback $rollback) {
            return $rollback->result;
        } catch (\Throwable) {
            // A transaction, confirmation, idempotency, or required-audit
            // failure can leave durable state unclear. Never leak the exception
            // or downgrade it to a safe denial/retry decision.
            return SensitiveActionGateResult::uncertain('sensitive_action_gate_outcome_uncertain');
        }
    }
}
