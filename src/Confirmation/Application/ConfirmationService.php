<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Application;

use Veyra\Confirmation\Domain\ConfirmationCheck;
use Veyra\Confirmation\Domain\ConfirmationConsumeResult;
use Veyra\Confirmation\Domain\ConfirmationId;
use Veyra\Confirmation\Domain\ConfirmationRecord;
use Veyra\Confirmation\Domain\ConfirmationRequest;
use Veyra\Confirmation\Domain\ConfirmationStatus;
use Veyra\Confirmation\Domain\IssuedConfirmation;
use Veyra\Identity\Domain\Actor;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Shared\Domain\Clock;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\SecretDigester;
use Veyra\Shared\Domain\SecretGenerator;
use Veyra\Shared\Domain\StateHash;
use Veyra\Shared\Domain\Uuid;

final class ConfirmationService
{
    public function __construct(
        private readonly ConfirmationRepository $confirmations,
        private readonly SecretDigester $digester,
        private readonly Clock $clock
    ) {
    }

    public function create(Actor $actor, ConfirmationRequest $request): IssuedConfirmation
    {
        $token = SecretGenerator::generate();
        $now = $this->clock->now();
        $record = new ConfirmationRecord(
            new ConfirmationId(Uuid::v4()),
            $this->digester->digest($token, 'confirmation'),
            ActorScope::fromActor($actor),
            $request->sessionId,
            $request->conversationId,
            $request->journeyId,
            $request->action,
            $request->resourceScope,
            $request->materialPayload,
            $request->stateHash,
            $request->summaryMessageId,
            $request->summaryVersion,
            $request->acknowledgements,
            $request->idempotencyScope,
            $request->correlationId,
            ConfirmationStatus::Active,
            1,
            $now->addSeconds($request->ttlSeconds),
            null,
            null,
            $now,
            $now
        );

        if (!$this->confirmations->insert($record)) {
            throw new \RuntimeException('Confirmation could not be persisted.');
        }

        return new IssuedConfirmation($record, $token);
    }

    public function validate(Actor $actor, string $rawToken, StateHash $currentState): ConfirmationCheck
    {
        if (!$this->validRawToken($rawToken)) {
            return ConfirmationCheck::invalid('confirmation_token_invalid');
        }

        $record = $this->confirmations->findByTokenDigest(
            ActorScope::fromActor($actor),
            $this->digester->digest($rawToken, 'confirmation')
        );

        if ($record === null) {
            return ConfirmationCheck::invalid('confirmation_not_found');
        }

        if (!$record->activeAt($this->clock->now())) {
            return ConfirmationCheck::invalid(
                $record->status === ConfirmationStatus::Active ? 'confirmation_expired' : 'confirmation_not_active',
                $record
            );
        }

        if (!$record->stateHash->equals($currentState)) {
            return ConfirmationCheck::invalid('confirmation_state_changed', $record);
        }

        return ConfirmationCheck::valid($record);
    }

    public function consume(
        Actor $actor,
        string $rawToken,
        StateHash $currentState,
        CorrelationId $correlationId
    ): ConfirmationConsumeResult {
        $check = $this->validate($actor, $rawToken, $currentState);

        if (!$check->valid || $check->record === null) {
            return ConfirmationConsumeResult::denied($check->code, $check->record);
        }

        $now = $this->clock->now();

        if (!$this->confirmations->consume($check->record, $currentState, $now, $correlationId)) {
            return ConfirmationConsumeResult::denied('confirmation_consume_conflict', $check->record);
        }

        return ConfirmationConsumeResult::consumed($check->record->asConsumed($now, $correlationId));
    }

    public function singleActiveForJourney(Actor $actor, ?string $journeyId): ConfirmationCheck
    {
        $records = $this->confirmations->findActiveForJourney(
            ActorScope::fromActor($actor),
            $journeyId,
            $this->clock->now(),
            2
        );

        if (count($records) !== 1) {
            return ConfirmationCheck::invalid($records === [] ? 'confirmation_not_found' : 'confirmation_ambiguous');
        }

        return ConfirmationCheck::valid($records[0]);
    }

    private function validRawToken(string $token): bool
    {
        return strlen($token) >= 32
            && strlen($token) <= 192
            && preg_match('/^[A-Za-z0-9_-]+$/D', $token) === 1;
    }
}
