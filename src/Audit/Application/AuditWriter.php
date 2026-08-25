<?php

declare(strict_types=1);

namespace Veyra\Audit\Application;

use Veyra\Audit\Domain\AuditEvent;
use Veyra\Identity\Domain\Actor;
use Veyra\Shared\Domain\Clock;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\Uuid;

final class AuditWriter
{
    public function __construct(
        private readonly AuditRepository $repository,
        private readonly Clock $clock
    ) {
    }

    /** @param array<string, mixed> $metadata */
    public function write(
        Actor $actor,
        string $action,
        string $targetType,
        ?string $targetId,
        string $resultCode,
        CorrelationId $correlationId,
        array $metadata = []
    ): ?string {
        $event = new AuditEvent(
            Uuid::v4(),
            $actor,
            $action,
            $targetType,
            $targetId,
            $resultCode,
            $correlationId,
            SafeAuditMetadata::sanitize($metadata),
            $this->clock->now()
        );

        return $this->repository->append($event) ? $event->id : null;
    }

    /** @param array<string, mixed> $metadata */
    public function writeRequired(
        Actor $actor,
        string $action,
        string $targetType,
        ?string $targetId,
        string $resultCode,
        CorrelationId $correlationId,
        array $metadata = []
    ): string {
        $reference = $this->write(
            $actor,
            $action,
            $targetType,
            $targetId,
            $resultCode,
            $correlationId,
            $metadata
        );

        if ($reference === null) {
            throw new \RuntimeException('Required audit record could not be persisted.');
        }

        return $reference;
    }
}

