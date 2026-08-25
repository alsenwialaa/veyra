<?php

declare(strict_types=1);

namespace Veyra\PaymentReview\Infrastructure;

use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Infrastructure\Database\Repository\ActorScopedRepository;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\PaymentReview\Application\PaymentReviewRepository;
use Veyra\PaymentReview\Domain\PaymentReview;

final class WpdbPaymentReviewRepository extends ActorScopedRepository implements PaymentReviewRepository
{
    public function __construct(\wpdb $database, TableNames $tables)
    {
        parent::__construct($database, $tables->paymentReviews());
    }

    public function insert(PaymentReview $review): bool
    {
        return $this->database->insert($this->table, $review->persistenceValues(), [
            '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s',
            '%s', '%s', '%s', '%s', '%d', '%s', '%s',
        ]) === 1;
    }

    public function save(PaymentReview $review, int $expectedVersion): bool
    {
        if ($review->version !== $expectedVersion + 1) {
            throw new \InvalidArgumentException('Payment review version transition is invalid.');
        }

        return $this->updateScopedVersioned($review->actor, $review->id, $expectedVersion, [
            'evidence_attachment_id' => $review->primaryAttachmentId,
            'evidence_json' => \Veyra\Shared\Domain\CanonicalJson::encode($review->evidence),
            'updated_at' => $review->updatedAt->toDatabase(),
        ]);
    }

    public function find(ActorScope $actor, string $reviewId): ?PaymentReview
    {
        $row = $this->findScopedRow($actor, $reviewId);
        if ($row === null && is_string($this->database->last_error ?? null) && $this->database->last_error !== '') {
            throw new \RuntimeException('Payment review persistence read failed.');
        }
        return $row !== null ? PaymentReview::fromRow($row) : null;
    }

    public function listForOrder(ActorScope $actor, int $orderId, int $limit = 50): array
    {
        $bounded = max(1, min(50, $limit));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT * FROM {$this->table} WHERE actor_type = %s AND actor_id = %s AND actor_key_hash = %s
             AND order_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d",
            $actor->actorType,
            $actor->actorId,
            $actor->hash(),
            $orderId,
            $bounded
        ), ARRAY_A);
        if (!is_array($rows)) {
            throw new \RuntimeException('Payment review persistence list failed.');
        }

        return array_values(array_map(
            static fn (array $row): PaymentReview => PaymentReview::fromRow($row),
            array_filter($rows, 'is_array')
        ));
    }
}
