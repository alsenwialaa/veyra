<?php

declare(strict_types=1);

namespace Veyra\Identity\Application;

use Veyra\Audit\Application\AuditWriter;
use Veyra\Identity\Domain\Actor;
use Veyra\Identity\Domain\ActorId;
use Veyra\Identity\Domain\ActorType;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Infrastructure\Database\WpdbTransactionManager;
use Veyra\Shared\Domain\Clock;
use Veyra\Shared\Domain\CorrelationId;

/** Authenticated, audited re-keying of eligible guest conversation state. */
final class GuestAccountLinkService
{
    public function __construct(
        private readonly GuestSessionManager $sessions,
        private readonly \wpdb $database,
        private readonly TableNames $tables,
        private readonly WpdbTransactionManager $transactions,
        private readonly AuditWriter $audit,
        private readonly Clock $clock
    ) {
    }

    public function link(?string $rawGuestToken, int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        $guest = $this->sessions->candidateForAuthenticatedLink($rawGuestToken);
        if ($guest === null) {
            return false;
        }

        $oldActorId = $guest->id->value();
        $oldHash = hash('sha256', 'guest:' . $oldActorId);
        $newActorId = 'wp-user-' . $userId;
        $newHash = hash('sha256', 'customer:' . $newActorId);
        $now = $this->clock->now()->toDatabase();
        $correlation = CorrelationId::generate();

        try {
            return $this->transactions->transactional(function () use (
                $guest,
                $userId,
                $oldActorId,
                $oldHash,
                $newActorId,
                $newHash,
                $now,
                $correlation
            ): bool {
                $counts = [];
                $counts['conversations'] = $this->rekey(
                    $this->tables->conversations(),
                    $oldActorId,
                    $oldHash,
                    $newActorId,
                    $newHash,
                    $now,
                    ', user_id = ' . (string) $userId,
                    true
                );
                $counts['messages'] = $this->rekey($this->tables->messages(), $oldActorId, $oldHash, $newActorId, $newHash, $now, '', false);
                $counts['journeys'] = $this->rekey($this->tables->journeys(), $oldActorId, $oldHash, $newActorId, $newHash, $now, ", status = 'paused'", true);
                $counts['focus'] = $this->rekey($this->tables->conversationFocus(), $oldActorId, $oldHash, $newActorId, $newHash, $now, '', true);
                // Requirement hashes cover only the complete criterion array,
                // so the actor can be re-keyed without invalidating state_hash.
                $counts['requirements'] = $this->rekey($this->tables->requirementStates(), $oldActorId, $oldHash, $newActorId, $newHash, $now, '', true);
                $counts['questions'] = $this->rekey($this->tables->pendingQuestions(), $oldActorId, $oldHash, $newActorId, $newHash, $now, '', true);
                // Context Bundle manifests contain only opaque selection
                // metadata. Their immutable metadata hash deliberately omits
                // lifecycle-owned actor fields, so authenticated guest linking
                // can re-key the owner without changing the recorded bundle
                // selection or its provider-projection hash.
                $counts['context_bundle_manifests'] = $this->rekey(
                    $this->tables->contextBundleManifests(),
                    $oldActorId,
                    $oldHash,
                    $newActorId,
                    $newHash,
                    $now,
                    '',
                    true
                );
                // Checkout is derived intent state whose digest includes the
                // actor. Discard it at the authenticated boundary and rebuild
                // from the still-authoritative Woo cart; direct re-keying would
                // invalidate its state hash and could carry stale addresses.
                $counts['checkout_discarded'] = $this->discardGuestCheckout($oldActorId, $oldHash);
                $counts['cases'] = $this->rekey($this->tables->cases(), $oldActorId, $oldHash, $newActorId, $newHash, $now, '', true);
                $counts['reviews'] = $this->rekey($this->tables->paymentReviews(), $oldActorId, $oldHash, $newActorId, $newHash, $now, '', true);
                $counts['attachments'] = $this->rekey($this->tables->attachments(), $oldActorId, $oldHash, $newActorId, $newHash, $now, '', true);

                $this->invalidateGuestConfirmations($oldActorId, $oldHash, $now);
                $this->invalidatePendingQuestions($newActorId, $newHash, $now);
                $counts['idempotency'] = $this->rekeyNonConflictingIdempotency($oldActorId, $oldHash, $newActorId, $newHash, $now);

                if (!$this->sessions->markLinkedToUser($guest, $userId)) {
                    throw new \RuntimeException('Guest-session link CAS failed.');
                }

                $actor = new Actor(new ActorId($newActorId), ActorType::Customer, $userId);
                $this->audit->writeRequired(
                    $actor,
                    'identity.guest_account_link',
                    'guest_session',
                    $guest->id->value(),
                    'guest_account_linked',
                    $correlation,
                    ['migrated_records' => $counts]
                );

                return true;
            });
        } catch (\Throwable) {
            return false;
        }
    }

    private function rekey(
        string $table,
        string $oldActorId,
        string $oldHash,
        string $newActorId,
        string $newHash,
        string $now,
        string $extraSet,
        bool $versioned
    ): int {
        $query = $this->database->prepare(
            "UPDATE {$table} SET actor_type = 'customer', actor_id = %s, actor_key_hash = %s,
             updated_at = %s" . ($versioned ? ', version = version + 1' : '') . "{$extraSet}
             WHERE actor_type = 'guest' AND actor_id = %s AND actor_key_hash = %s",
            $newActorId,
            $newHash,
            $now,
            $oldActorId,
            $oldHash
        );
        $result = $this->database->query($query);
        if ($result === false) {
            throw new \RuntimeException('Guest state re-key failed.');
        }

        return (int) $result;
    }

    private function invalidateGuestConfirmations(string $oldActorId, string $oldHash, string $now): void
    {
        $table = $this->tables->confirmations();
        $result = $this->database->query($this->database->prepare(
            "UPDATE {$table} SET status = 'invalidated', invalidation_reason = 'identity_changed',
             updated_at = %s, version = version + 1
             WHERE actor_type = 'guest' AND actor_id = %s AND actor_key_hash = %s AND status = 'active'",
            $now,
            $oldActorId,
            $oldHash
        ));
        if ($result === false) {
            throw new \RuntimeException('Guest confirmation invalidation failed.');
        }
    }

    private function invalidatePendingQuestions(string $newActorId, string $newHash, string $now): void
    {
        $table = $this->tables->pendingQuestions();
        $result = $this->database->query($this->database->prepare(
            "UPDATE {$table} SET state = 'invalidated', invalidation_reason = 'identity_changed',
             updated_at = %s, version = version + 1
             WHERE actor_type = 'customer' AND actor_id = %s AND actor_key_hash = %s AND state = 'active'",
            $now,
            $newActorId,
            $newHash
        ));
        if ($result === false) {
            throw new \RuntimeException('Linked pending-question invalidation failed.');
        }
    }

    private function discardGuestCheckout(string $oldActorId, string $oldHash): int
    {
        $table = $this->tables->checkoutSessions();
        $result = $this->database->query($this->database->prepare(
            "DELETE FROM {$table} WHERE actor_type = 'guest' AND actor_id = %s AND actor_key_hash = %s",
            $oldActorId,
            $oldHash
        ));
        if ($result === false) {
            throw new \RuntimeException('Guest checkout reset failed.');
        }

        return (int) $result;
    }

    private function rekeyNonConflictingIdempotency(
        string $oldActorId,
        string $oldHash,
        string $newActorId,
        string $newHash,
        string $now
    ): int {
        $table = $this->tables->idempotency();
        $migrated = $this->database->query($this->database->prepare(
            "UPDATE {$table} AS source LEFT JOIN {$table} AS target
             ON target.actor_key_hash = %s AND target.action_key_hash = source.action_key_hash
             AND target.key_digest = source.key_digest
             SET source.actor_type = 'customer', source.actor_id = %s, source.actor_key_hash = %s,
             source.updated_at = %s, source.version = source.version + 1
             WHERE source.actor_type = 'guest' AND source.actor_id = %s AND source.actor_key_hash = %s
             AND target.id IS NULL",
            $newHash,
            $newActorId,
            $newHash,
            $now,
            $oldActorId,
            $oldHash
        ));
        if ($migrated === false) {
            throw new \RuntimeException('Guest idempotency re-key failed.');
        }

        return (int) $migrated;
    }
}
