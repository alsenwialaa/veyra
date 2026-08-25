<?php

declare(strict_types=1);

namespace Veyra\Tests\Support;

use Veyra\Identity\Application\GuestSessionRepository;
use Veyra\Identity\Domain\GuestSessionRecord;
use Veyra\Shared\Domain\UtcInstant;

final class InMemoryGuestSessionRepository implements GuestSessionRepository
{
    private ?GuestSessionRecord $record = null;

    public function current(): ?GuestSessionRecord
    {
        return $this->record;
    }

    public function insert(GuestSessionRecord $session): bool
    {
        if ($this->record !== null) {
            return false;
        }
        $this->record = $session;
        return true;
    }

    public function findActiveByTokenDigest(string $tokenDigest, UtcInstant $now): ?GuestSessionRecord
    {
        return $this->record !== null
            && hash_equals($this->record->tokenDigest, $tokenDigest)
            && $this->record->activeAt($now)
                ? $this->record
                : null;
    }

    public function touch(GuestSessionRecord $session, UtcInstant $seenAt): bool
    {
        if (!$this->matches($session) || $session->status !== 'active') {
            return false;
        }
        $this->record = $this->copy($session, null, 'active', $session->version + 1, $seenAt);
        return true;
    }

    public function linkToUser(GuestSessionRecord $session, int $userId, UtcInstant $linkedAt): bool
    {
        if ($userId < 1 || !$this->matches($session) || $session->status !== 'active') {
            return false;
        }
        $this->record = $this->copy($session, $userId, 'linked', $session->version + 1, $linkedAt);
        return true;
    }

    public function revoke(GuestSessionRecord $session, UtcInstant $revokedAt): bool
    {
        if (!$this->matches($session) || $session->status !== 'active') {
            return false;
        }
        $this->record = $this->copy($session, $session->linkedUserId, 'revoked', $session->version + 1, $revokedAt);
        return true;
    }

    private function matches(GuestSessionRecord $session): bool
    {
        return $this->record !== null
            && $this->record->id->value() === $session->id->value()
            && $this->record->version === $session->version;
    }

    private function copy(GuestSessionRecord $source, ?int $userId, string $status, int $version, UtcInstant $seenAt): GuestSessionRecord
    {
        return new GuestSessionRecord(
            $source->id,
            $source->tokenDigest,
            $source->csrfDigest,
            $source->actorKey,
            $userId,
            $status,
            $version,
            $source->expiresAt,
            $seenAt,
            $source->createdAt
        );
    }
}
