<?php

declare(strict_types=1);

namespace Veyra\Audit\Domain;

use Veyra\Identity\Domain\Actor;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Shared\Domain\Uuid;

final class AuditEvent
{
    /** @param array<string, scalar|array|null> $metadata */
    public function __construct(
        public readonly string $id,
        public readonly Actor $actor,
        public readonly string $action,
        public readonly string $targetType,
        public readonly ?string $targetId,
        public readonly string $resultCode,
        public readonly CorrelationId $correlationId,
        public readonly array $metadata,
        public readonly UtcInstant $occurredAt
    ) {
        if (!Uuid::isValid($id)) {
            throw new \InvalidArgumentException('Audit event ID must be a UUIDv4.');
        }

        foreach ([$action, $targetType, $resultCode] as $code) {
            if (preg_match('/^[a-z][a-z0-9_.:-]{1,190}$/D', $code) !== 1) {
                throw new \InvalidArgumentException('Audit event code is invalid.');
            }
        }
    }
}

