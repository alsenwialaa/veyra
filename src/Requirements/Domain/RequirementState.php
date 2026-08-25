<?php

declare(strict_types=1);

namespace Veyra\Requirements\Domain;

use Veyra\Shared\Domain\CanonicalJson;

/** Actor-owned, conversation-scoped head for the complete requirement history. */
final class RequirementState
{
    private const MAXIMUM_CRITERIA = 64;
    private const MAXIMUM_ENCODED_HISTORY_BYTES = 49152;
    private const MAXIMUM_ACTIVE_PROJECTION_BYTES = 24576;

    /**
     * @param list<RequirementCriterion> $criteria
     */
    private function __construct(
        public readonly string $conversationId,
        public readonly string $actorType,
        public readonly string $actorId,
        public readonly int $resourceVersion,
        public readonly string $stateHash,
        public readonly array $criteria,
        public readonly ?string $lastSourceMessageId,
        public readonly string $createdAt,
        public readonly string $updatedAt
    ) {
    }

    public static function empty(
        string $conversationId,
        string $actorType,
        string $actorId,
        string $now
    ): self {
        self::validateScope($conversationId, $actorType, $actorId);
        $timestamp = self::timestamp($now);

        return new self(
            $conversationId,
            $actorType,
            $actorId,
            0,
            self::hashCriteria([]),
            [],
            null,
            $timestamp,
            $timestamp
        );
    }

    /**
     * @param list<RequirementCriterion> $criteria
     */
    public static function fromStored(
        string $conversationId,
        string $actorType,
        string $actorId,
        int $resourceVersion,
        string $stateHash,
        array $criteria,
        ?string $lastSourceMessageId,
        string $createdAt,
        string $updatedAt
    ): self {
        self::validateScope($conversationId, $actorType, $actorId);
        if ($resourceVersion < 1 || preg_match('/^[a-f0-9]{64}$/D', $stateHash) !== 1) {
            throw new \InvalidArgumentException('Stored requirement-state head is invalid.');
        }
        $criteria = self::canonicalCriteria($criteria);
        $calculatedHash = self::hashCriteria($criteria);
        if (!hash_equals($calculatedHash, $stateHash)) {
            throw new \InvalidArgumentException('Stored requirement-state hash does not match its complete history.');
        }
        self::validateSourceMessageId($lastSourceMessageId);
        $createdAt = self::timestamp($createdAt);
        $updatedAt = self::timestamp($updatedAt);
        if (strcmp($updatedAt, $createdAt) < 0) {
            throw new \InvalidArgumentException('Requirement-state timestamps are invalid.');
        }

        return new self(
            $conversationId,
            $actorType,
            $actorId,
            $resourceVersion,
            $stateHash,
            $criteria,
            $lastSourceMessageId,
            $createdAt,
            $updatedAt
        );
    }

    /**
     * Creates the one and only successor to this head. The supplied criteria
     * are the complete history, including disputed and superseded records.
     *
     * @param list<RequirementCriterion> $criteria
     */
    public function next(array $criteria, string $sourceMessageId, string $now): self
    {
        self::validateSourceMessageId($sourceMessageId);
        $criteria = self::canonicalCriteria($criteria);
        self::validateSuccessorHistory($this->criteria, $criteria);
        if (hash_equals($this->stateHash, self::hashCriteria($criteria))) {
            throw new \InvalidArgumentException('Requirement-state successor must change the complete history.');
        }
        $updatedAt = self::timestamp($now);
        if (strcmp($updatedAt, $this->createdAt) < 0) {
            throw new \InvalidArgumentException('Requirement-state successor timestamp is invalid.');
        }

        return new self(
            $this->conversationId,
            $this->actorType,
            $this->actorId,
            $this->resourceVersion + 1,
            self::hashCriteria($criteria),
            $criteria,
            $sourceMessageId,
            $this->createdAt,
            $updatedAt
        );
    }

    /**
     * The aggregate is a complete append-only history. Existing criteria may
     * only advance through the explicit disputed/superseded status transition;
     * they cannot be removed, reordered, or have their original evidence and
     * meaning rewritten by a later head.
     *
     * @param list<RequirementCriterion> $previous
     * @param list<RequirementCriterion> $next
     */
    private static function validateSuccessorHistory(array $previous, array $next): void
    {
        if (count($next) < count($previous)) {
            throw new \InvalidArgumentException('Requirement-state successor dropped history.');
        }
        foreach ($previous as $index => $before) {
            $after = $next[$index] ?? null;
            if (!$after instanceof RequirementCriterion || $after->id !== $before->id) {
                throw new \InvalidArgumentException('Requirement-state successor reordered history.');
            }
            if ($after->toArray() === $before->toArray()) {
                continue;
            }
            if ($before->status === 'superseded'
                || !in_array($after->status, ['disputed', 'superseded'], true)
                || $after->version !== $before->version + 1
                || $after->statusSourceMessageId === null
                || strcmp($after->updatedAt, $before->updatedAt) < 0
            ) {
                throw new \InvalidArgumentException('Requirement-state history transition is invalid.');
            }
            $beforeCore = $before->toArray();
            $afterCore = $after->toArray();
            foreach (['status', 'superseded_by', 'version', 'updated_at', 'status_source_message_id'] as $mutable) {
                unset($beforeCore[$mutable], $afterCore[$mutable]);
            }
            if (CanonicalJson::encode($beforeCore) !== CanonicalJson::encode($afterCore)) {
                throw new \InvalidArgumentException('Requirement-state history meaning is immutable.');
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function criteriaArray(): array
    {
        return array_map(
            static fn (RequirementCriterion $criterion): array => $criterion->toArray(),
            $this->criteria
        );
    }

    /** @param list<RequirementCriterion> $criteria */
    private static function hashCriteria(array $criteria): string
    {
        return hash('sha256', self::encodedCriteria($criteria));
    }

    /** @param list<RequirementCriterion> $criteria */
    private static function encodedCriteria(array $criteria): string
    {
        return CanonicalJson::encode(array_map(
            static fn (RequirementCriterion $criterion): array => $criterion->toArray(),
            $criteria
        ));
    }

    /**
     * @param list<RequirementCriterion> $criteria
     * @return list<RequirementCriterion>
     */
    private static function canonicalCriteria(array $criteria): array
    {
        if (!array_is_list($criteria) || count($criteria) > self::MAXIMUM_CRITERIA) {
            throw new \InvalidArgumentException('Requirement-state history is invalid.');
        }

        $canonical = [];
        $ids = [];
        foreach ($criteria as $criterion) {
            if (!$criterion instanceof RequirementCriterion || isset($ids[$criterion->id])) {
                throw new \InvalidArgumentException('Requirement-state criteria are invalid.');
            }
            // Round-trip through the stored contract so equivalent instants and
            // nested values always produce one stable canonical state hash.
            $criterion = RequirementCriterion::fromStored($criterion->toArray());
            $ids[$criterion->id] = true;
            $canonical[] = $criterion;
        }

        if (strlen(self::encodedCriteria($canonical)) > self::MAXIMUM_ENCODED_HISTORY_BYTES) {
            throw new \InvalidArgumentException('Requirement-state history exceeds its encoded-size budget.');
        }
        $active = array_values(array_filter(
            $canonical,
            static fn (RequirementCriterion $criterion): bool => $criterion->status === 'active'
        ));
        if (strlen(self::encodedCriteria($active)) > self::MAXIMUM_ACTIVE_PROJECTION_BYTES) {
            throw new \InvalidArgumentException('Active requirement projection exceeds its context budget.');
        }
        self::validateGraph($canonical);

        return $canonical;
    }

    /** @param list<RequirementCriterion> $criteria */
    private static function validateGraph(array $criteria): void
    {
        $byId = [];
        $positions = [];
        $activeSlots = [];
        foreach ($criteria as $position => $criterion) {
            $byId[$criterion->id] = $criterion;
            $positions[$criterion->id] = $position;
            if ($criterion->status === 'active') {
                $slot = self::slot($criterion);
                if (isset($activeSlots[$slot])) {
                    throw new \InvalidArgumentException('Requirement-state contains competing active criteria.');
                }
                $activeSlots[$slot] = true;
            }
        }

        foreach ($criteria as $criterion) {
            if ($criterion->status === 'superseded') {
                if ($criterion->supersededBy === null || $criterion->statusSourceMessageId === null) {
                    throw new \InvalidArgumentException('Superseded requirement linkage is incomplete.');
                }
            } elseif ($criterion->supersededBy !== null) {
                throw new \InvalidArgumentException('Only superseded requirements may carry a successor.');
            }

            foreach ($criterion->supersedes as $targetId) {
                $target = $byId[$targetId] ?? null;
                if (!$target instanceof RequirementCriterion
                    || ($positions[$targetId] ?? PHP_INT_MAX) >= ($positions[$criterion->id] ?? -1)
                    || $target->status !== 'superseded'
                    || $target->supersededBy !== $criterion->id
                ) {
                    throw new \InvalidArgumentException('Requirement supersession graph is invalid.');
                }
            }
            if ($criterion->supersededBy !== null && $criterion->supersededBy !== 'removed') {
                $successor = $byId[$criterion->supersededBy] ?? null;
                if (!$successor instanceof RequirementCriterion
                    || !in_array($criterion->id, $successor->supersedes, true)
                ) {
                    throw new \InvalidArgumentException('Requirement successor linkage is invalid.');
                }
            }
        }
    }

    private static function slot(RequirementCriterion $criterion): string
    {
        if (!in_array($criterion->field, ['attribute', 'compatibility', 'preference'], true)) {
            return $criterion->field;
        }
        $value = is_array($criterion->value) ? $criterion->value : [];
        $selector = $criterion->field === 'attribute' ? 'name' : 'key';
        $selected = is_string($value[$selector] ?? null) && $value[$selector] !== ''
            ? $value[$selector]
            : '__unspecified__';

        return $criterion->field . ':' . $selected;
    }

    private static function validateScope(string $conversationId, string $actorType, string $actorId): void
    {
        if ($conversationId === '' || strlen($conversationId) > 36
            || !in_array($actorType, ['guest', 'customer'], true)
            || $actorId === '' || strlen($actorId) > 191
        ) {
            throw new \InvalidArgumentException('Requirement-state actor scope is invalid.');
        }
    }

    private static function validateSourceMessageId(?string $sourceMessageId): void
    {
        if ($sourceMessageId !== null && ($sourceMessageId === '' || strlen($sourceMessageId) > 36)) {
            throw new \InvalidArgumentException('Requirement-state source message is invalid.');
        }
    }

    private static function timestamp(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Requirement-state timestamp is invalid.');
        }
    }
}
