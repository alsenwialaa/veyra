<?php

declare(strict_types=1);

namespace Veyra\Identity\Domain;

use Veyra\Shared\Domain\UtcInstant;

final class GuestSessionRecord
{
    public function __construct(
        public readonly GuestSessionId $id,
        public readonly string $tokenDigest,
        public readonly string $csrfDigest,
        public readonly string $actorKey,
        public readonly ?int $linkedUserId,
        public readonly string $status,
        public readonly int $version,
        public readonly UtcInstant $expiresAt,
        public readonly UtcInstant $lastSeenAt,
        public readonly UtcInstant $createdAt
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $tokenDigest) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $csrfDigest) !== 1) {
            throw new \InvalidArgumentException('Guest-session secret digests are invalid.');
        }

        if (!in_array($status, ['active', 'linked', 'revoked', 'expired'], true)) {
            throw new \InvalidArgumentException('Guest-session status is invalid.');
        }
    }

    public function activeAt(UtcInstant $now): bool
    {
        return $this->status === 'active' && !$this->expiresAt->isAtOrBefore($now);
    }
}

