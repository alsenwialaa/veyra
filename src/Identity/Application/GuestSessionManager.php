<?php

declare(strict_types=1);

namespace Veyra\Identity\Application;

use Veyra\Identity\Domain\GuestSessionId;
use Veyra\Identity\Domain\GuestSessionRecord;
use Veyra\Shared\Domain\Clock;
use Veyra\Shared\Domain\SecretDigester;
use Veyra\Shared\Domain\SecretGenerator;
use Veyra\Shared\Domain\Uuid;

final class GuestSessionManager
{
    public const COOKIE_NAME = 'veyra_guest_session';

    public function __construct(
        private readonly GuestSessionRepository $sessions,
        private readonly SecretDigester $digester,
        private readonly Clock $clock,
        private readonly int $lifetimeSeconds = 604800
    ) {
        if ($lifetimeSeconds < 900 || $lifetimeSeconds > 2592000) {
            throw new \InvalidArgumentException('Guest-session lifetime is outside the safe bound.');
        }
    }

    public function resolveFromRawToken(?string $rawToken): ?GuestSessionContext
    {
        $context = $this->inspectFromRawToken($rawToken);
        if ($context === null) {
            return null;
        }

        if ($rawToken === null || !$this->validRawToken($rawToken)) {
            return null;
        }

        $now = $this->clock->now();
        $session = $context->session;

        if ($this->sessions->touch($session, $now)) {
            $session = new GuestSessionRecord(
                $session->id,
                $session->tokenDigest,
                $session->csrfDigest,
                $session->actorKey,
                $session->linkedUserId,
                $session->status,
                $session->version + 1,
                $session->expiresAt,
                $now,
                $session->createdAt
            );
        } else {
            // A concurrent touch may have advanced the CAS version. Re-read so
            // downstream authenticated linking never receives a stale record.
            $refreshed = $this->sessions->findActiveByTokenDigest(
                $this->digester->digest($rawToken, 'guest-session'),
                $now
            );
            if ($refreshed === null || !$refreshed->activeAt($now)) {
                return null;
            }
            $session = $refreshed;
        }

        return new GuestSessionContext($session);
    }

    /** Read-only token inspection for authorization and CSRF preflight. */
    public function inspectFromRawToken(?string $rawToken): ?GuestSessionContext
    {
        if ($rawToken === null || !$this->validRawToken($rawToken)) {
            return null;
        }

        $now = $this->clock->now();
        $session = $this->sessions->findActiveByTokenDigest(
            $this->digester->digest($rawToken, 'guest-session'),
            $now
        );

        return $session !== null && $session->activeAt($now)
            ? new GuestSessionContext($session)
            : null;
    }

    public function candidateForAuthenticatedLink(?string $rawToken): ?GuestSessionRecord
    {
        if ($rawToken === null || !$this->validRawToken($rawToken)) {
            return null;
        }
        $now = $this->clock->now();
        $session = $this->sessions->findActiveByTokenDigest(
            $this->digester->digest($rawToken, 'guest-session'),
            $now
        );

        return $session !== null && $session->activeAt($now) ? $session : null;
    }

    public function markLinkedToUser(GuestSessionRecord $session, int $userId): bool
    {
        return $userId > 0 && $this->sessions->linkToUser($session, $userId, $this->clock->now());
    }

    public function create(): array
    {
        $rawToken = SecretGenerator::generate();
        $csrfToken = SecretGenerator::generate();
        $id = new GuestSessionId(Uuid::v4());
        $now = $this->clock->now();
        $session = new GuestSessionRecord(
            $id,
            $this->digester->digest($rawToken, 'guest-session'),
            $this->digester->digest($csrfToken, 'guest-csrf'),
            'guest:' . $id->value(),
            null,
            'active',
            1,
            $now->addSeconds($this->lifetimeSeconds),
            $now,
            $now
        );

        if (!$this->sessions->insert($session)) {
            throw new \RuntimeException('Could not create a secure guest session.');
        }

        return [
            'context' => new GuestSessionContext($session, $csrfToken),
            'raw_token' => $rawToken,
        ];
    }

    public function verifyCsrf(GuestSessionRecord $session, string $rawCsrfToken): bool
    {
        if (!$this->validRawToken($rawCsrfToken)) {
            return false;
        }

        return hash_equals(
            $session->csrfDigest,
            $this->digester->digest($rawCsrfToken, 'guest-csrf')
        );
    }

    private function validRawToken(string $token): bool
    {
        return strlen($token) >= 32
            && strlen($token) <= 192
            && preg_match('/^[A-Za-z0-9_-]+$/D', $token) === 1;
    }
}
