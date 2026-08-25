<?php

declare(strict_types=1);

namespace Veyra\Identity\Infrastructure;

use Veyra\Identity\Application\GuestSessionRepository;
use Veyra\Identity\Domain\GuestSessionId;
use Veyra\Identity\Domain\GuestSessionRecord;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Shared\Domain\UtcInstant;

final class WpdbGuestSessionRepository implements GuestSessionRepository
{
    private readonly string $table;

    public function __construct(private readonly \wpdb $database, TableNames $tables)
    {
        $this->table = $tables->guestSessions();
    }

    public function insert(GuestSessionRecord $session): bool
    {
        return $this->database->insert(
            $this->table,
            [
                'public_id' => $session->id->value(),
                'token_digest' => $session->tokenDigest,
                'csrf_digest' => $session->csrfDigest,
                'actor_key' => $session->actorKey,
                'actor_key_hash' => hash('sha256', $session->actorKey),
                'user_id' => $session->linkedUserId,
                'status' => $session->status,
                'version' => $session->version,
                'expires_at' => $session->expiresAt->toDatabase(),
                'last_seen_at' => $session->lastSeenAt->toDatabase(),
                'created_at' => $session->createdAt->toDatabase(),
                'updated_at' => $session->createdAt->toDatabase(),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s']
        ) === 1;
    }

    public function findActiveByTokenDigest(string $tokenDigest, UtcInstant $now): ?GuestSessionRecord
    {
        $query = $this->database->prepare(
            "SELECT * FROM {$this->table} WHERE token_digest = %s AND status = 'active' AND expires_at > %s LIMIT 1",
            $tokenDigest,
            $now->toDatabase()
        );
        $row = $this->database->get_row($query, ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function touch(GuestSessionRecord $session, UtcInstant $seenAt): bool
    {
        $query = $this->database->prepare(
            "UPDATE {$this->table} SET last_seen_at = %s, updated_at = %s, version = version + 1
             WHERE public_id = %s AND token_digest = %s AND status = 'active' AND version = %d",
            $seenAt->toDatabase(),
            $seenAt->toDatabase(),
            $session->id->value(),
            $session->tokenDigest,
            $session->version
        );

        return $this->database->query($query) === 1;
    }

    public function linkToUser(GuestSessionRecord $session, int $userId, UtcInstant $linkedAt): bool
    {
        if ($userId < 1) {
            return false;
        }

        $query = $this->database->prepare(
            "UPDATE {$this->table} SET user_id = %d, status = 'linked', updated_at = %s, version = version + 1
             WHERE public_id = %s AND token_digest = %s AND status = 'active' AND version = %d",
            $userId,
            $linkedAt->toDatabase(),
            $session->id->value(),
            $session->tokenDigest,
            $session->version
        );

        return $this->database->query($query) === 1;
    }

    public function revoke(GuestSessionRecord $session, UtcInstant $revokedAt): bool
    {
        $query = $this->database->prepare(
            "UPDATE {$this->table} SET status = 'revoked', updated_at = %s, version = version + 1
             WHERE public_id = %s AND token_digest = %s AND status = 'active' AND version = %d",
            $revokedAt->toDatabase(),
            $session->id->value(),
            $session->tokenDigest,
            $session->version
        );

        return $this->database->query($query) === 1;
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): GuestSessionRecord
    {
        return new GuestSessionRecord(
            new GuestSessionId((string) $row['public_id']),
            (string) $row['token_digest'],
            (string) $row['csrf_digest'],
            (string) $row['actor_key'],
            isset($row['user_id']) ? (int) $row['user_id'] : null,
            (string) $row['status'],
            (int) $row['version'],
            UtcInstant::fromDatabase((string) $row['expires_at']),
            UtcInstant::fromDatabase((string) $row['last_seen_at']),
            UtcInstant::fromDatabase((string) $row['created_at'])
        );
    }
}

