<?php

declare(strict_types=1);

namespace Veyra\Tests\PaymentReview\Support;

use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\PaymentReview\Application\PaymentReviewRepository;
use Veyra\PaymentReview\Domain\PaymentReview;

final class InMemoryPaymentReviewRepository implements PaymentReviewRepository
{
    /** @var array<string, PaymentReview> */
    private array $items = [];

    public function insert(PaymentReview $review): bool
    {
        if (isset($this->items[$review->id])) {
            return false;
        }
        $this->items[$review->id] = $review;
        return true;
    }

    public function save(PaymentReview $review, int $expectedVersion): bool
    {
        $current = $this->items[$review->id] ?? null;
        if (!$current instanceof PaymentReview || $current->version !== $expectedVersion || $review->version !== $expectedVersion + 1) {
            return false;
        }
        $this->items[$review->id] = $review;
        return true;
    }

    public function find(ActorScope $actor, string $reviewId): ?PaymentReview
    {
        $item = $this->items[$reviewId] ?? null;
        return $item instanceof PaymentReview && hash_equals($item->actor->hash(), $actor->hash()) ? $item : null;
    }

    public function listForOrder(ActorScope $actor, int $orderId, int $limit = 50): array
    {
        $matches = array_values(array_filter($this->items, static fn (PaymentReview $item): bool =>
            hash_equals($item->actor->hash(), $actor->hash()) && $item->orderId === $orderId
        ));
        usort($matches, static fn (PaymentReview $left, PaymentReview $right): int => strcmp($right->updatedAt->toDatabase(), $left->updatedAt->toDatabase()));
        return array_slice($matches, 0, max(1, min(50, $limit)));
    }
}
