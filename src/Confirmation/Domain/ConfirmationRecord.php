<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Domain;

use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\StateHash;
use Veyra\Shared\Domain\UtcInstant;

final class ConfirmationRecord
{
    /**
     * @param array<string, mixed> $resourceScope
     * @param array<string, mixed> $materialPayload
     * @param list<string>         $acknowledgements
     */
    public function __construct(
        public readonly ConfirmationId $id,
        public readonly string $tokenDigest,
        public readonly ActorScope $actor,
        public readonly ?string $sessionId,
        public readonly ?string $conversationId,
        public readonly ?string $journeyId,
        public readonly string $action,
        public readonly array $resourceScope,
        public readonly array $materialPayload,
        public readonly StateHash $stateHash,
        public readonly string $summaryMessageId,
        public readonly int $summaryVersion,
        public readonly array $acknowledgements,
        public readonly string $idempotencyScope,
        public readonly CorrelationId $correlationId,
        public readonly ConfirmationStatus $status,
        public readonly int $version,
        public readonly UtcInstant $expiresAt,
        public readonly ?UtcInstant $consumedAt,
        public readonly ?string $invalidationReason,
        public readonly UtcInstant $createdAt,
        public readonly UtcInstant $updatedAt
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $tokenDigest) !== 1) {
            throw new \InvalidArgumentException('Confirmation token digest is invalid.');
        }

        if (preg_match('/^[a-z][a-z0-9_.:-]{2,190}$/D', $action) !== 1 || $summaryVersion < 1 || $version < 1) {
            throw new \InvalidArgumentException('Confirmation record fields are invalid.');
        }
    }

    public function activeAt(UtcInstant $now): bool
    {
        return $this->status === ConfirmationStatus::Active && !$this->expiresAt->isAtOrBefore($now);
    }

    public function asConsumed(UtcInstant $consumedAt, CorrelationId $consumptionCorrelationId): self
    {
        return new self(
            $this->id,
            $this->tokenDigest,
            $this->actor,
            $this->sessionId,
            $this->conversationId,
            $this->journeyId,
            $this->action,
            $this->resourceScope,
            $this->materialPayload,
            $this->stateHash,
            $this->summaryMessageId,
            $this->summaryVersion,
            $this->acknowledgements,
            $this->idempotencyScope,
            $consumptionCorrelationId,
            ConfirmationStatus::Consumed,
            $this->version + 1,
            $this->expiresAt,
            $consumedAt,
            null,
            $this->createdAt,
            $consumedAt
        );
    }
}
