<?php

declare(strict_types=1);

namespace Veyra\PaymentReview\Application;

use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\PaymentReview\Domain\PaymentReview;

interface PaymentReviewRepository
{
    public function insert(PaymentReview $review): bool;

    public function save(PaymentReview $review, int $expectedVersion): bool;

    public function find(ActorScope $actor, string $reviewId): ?PaymentReview;

    /** @return list<PaymentReview> */
    public function listForOrder(ActorScope $actor, int $orderId, int $limit = 50): array;
}
