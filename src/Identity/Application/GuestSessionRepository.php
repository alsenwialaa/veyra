<?php

declare(strict_types=1);

namespace Veyra\Identity\Application;

use Veyra\Identity\Domain\GuestSessionRecord;
use Veyra\Shared\Domain\UtcInstant;

interface GuestSessionRepository
{
    public function insert(GuestSessionRecord $session): bool;

    public function findActiveByTokenDigest(string $tokenDigest, UtcInstant $now): ?GuestSessionRecord;

    public function touch(GuestSessionRecord $session, UtcInstant $seenAt): bool;

    public function linkToUser(GuestSessionRecord $session, int $userId, UtcInstant $linkedAt): bool;

    public function revoke(GuestSessionRecord $session, UtcInstant $revokedAt): bool;
}

