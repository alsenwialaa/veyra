<?php

declare(strict_types=1);

namespace Veyra\Tests\PaymentReview;

use PHPUnit\Framework\TestCase;
use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContext;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Media\Domain\Attachment;
use Veyra\PaymentReview\Application\PaymentReviewService;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Media\Support\InMemoryAttachmentRepository;
use Veyra\Tests\PaymentReview\Support\FakePaymentReviewAuthority;
use Veyra\Tests\PaymentReview\Support\InMemoryPaymentReviewRepository;
use Veyra\Tests\Support\FrozenClock;

final class PaymentReviewServiceTest extends TestCase
{
    public function testDraftUsesOwnedCleanEvidenceAndOptimisticVersion(): void
    {
        $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
        $attachments = new InMemoryAttachmentRepository();
        $attachment = Attachment::quarantined(
            new ActorScope('customer', 'wp-user-42'),
            '11111111-1111-4111-8111-111111111111',
            null,
            'payment_evidence',
            'private_fs',
            '2026/08/' . str_repeat('a', 48) . '.png',
            'image/png',
            256,
            str_repeat('c', 64),
            $clock->now(),
            86400
        )->withScanResult('clean', $clock->now());
        self::assertTrue($attachments->insert($attachment));
        $reviews = new InMemoryPaymentReviewRepository();
        $service = new PaymentReviewService($reviews, $attachments, new FakePaymentReviewAuthority(), new FoundationActorMapper(), $clock);
        $context = $this->context('wp-user-42', 42);

        $created = $service->createOrUpdateDraft($context, [
            'order_id' => 7001,
            'expected_version' => 0,
            'amount' => '100.00',
            'currency' => 'USD',
            'reference' => 'BANK-REF-7',
            'attachment_ids' => [$attachment->id],
        ]);
        self::assertSame('succeeded', $created->status);
        self::assertNotNull($created->review);
        self::assertSame('draft', $created->review->submissionStatus);
        self::assertNull($created->review->decisionStatus);
        self::assertNull($created->review->transitionStatus);

        $updated = $service->createOrUpdateDraft($context, [
            'order_id' => 7001,
            'review_id' => $created->review->id,
            'expected_version' => 1,
            'note' => 'Customer supplied correction.',
        ]);
        self::assertSame('succeeded', $updated->status);
        self::assertSame(2, $updated->review?->version);
        self::assertCount(1, $updated->review?->evidence['draft_history'] ?? []);

        $stale = $service->createOrUpdateDraft($context, [
            'order_id' => 7001,
            'review_id' => $created->review->id,
            'expected_version' => 1,
            'note' => 'Stale update.',
        ]);
        self::assertSame('stale', $stale->status);
    }

    public function testActorScopeAndExactRecordSelectionBlockUnsafeReuse(): void
    {
        $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
        $reviews = new InMemoryPaymentReviewRepository();
        $service = new PaymentReviewService(
            $reviews,
            new InMemoryAttachmentRepository(),
            new FakePaymentReviewAuthority(),
            new FoundationActorMapper(),
            $clock
        );
        $context = $this->context('wp-user-42', 42);
        $created = $service->createOrUpdateDraft($context, [
            'order_id' => 7001,
            'expected_version' => 0,
            'note' => 'Paid by bank transfer.',
        ]);
        self::assertSame('succeeded', $created->status);

        $duplicate = $service->createOrUpdateDraft($context, [
            'order_id' => 7001,
            'expected_version' => 0,
            'note' => 'Second draft.',
        ]);
        self::assertSame('blocked', $duplicate->status);
        self::assertSame('payment_review_existing_record_requires_exact_id', $duplicate->code);

        $otherActor = $this->context('wp-user-43', 43);
        $denied = $service->getStatus($otherActor, (string) $created->review?->id);
        self::assertSame('blocked', $denied->status);
    }

    public function testExpiredEvidenceCannotBeAddedToPaymentReview(): void
    {
        $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
        $attachments = new InMemoryAttachmentRepository();
        $attachment = Attachment::quarantined(
            new ActorScope('customer', 'wp-user-42'),
            '11111111-1111-4111-8111-111111111111',
            null,
            'payment_evidence',
            'private_fs',
            '2026/08/' . str_repeat('d', 48) . '.png',
            'image/png',
            256,
            str_repeat('e', 64),
            $clock->now(),
            3600
        )->withScanResult('clean', $clock->now());
        self::assertTrue($attachments->insert($attachment));
        $clock->advance(3600);

        $service = new PaymentReviewService(
            new InMemoryPaymentReviewRepository(),
            $attachments,
            new FakePaymentReviewAuthority(),
            new FoundationActorMapper(),
            $clock
        );
        $result = $service->createOrUpdateDraft($this->context('wp-user-42', 42), [
            'order_id' => 7001,
            'expected_version' => 0,
            'attachment_ids' => [$attachment->id],
        ]);

        self::assertSame('blocked', $result->status);
        self::assertSame('payment_review_attachment_not_clean_or_wrong_purpose', $result->code);
    }

    private function context(string $actorId, int $userId): ToolContext
    {
        return new ToolContext(
            'customer',
            $actorId,
            $userId,
            null,
            '11111111-1111-4111-8111-111111111111',
            [],
            ['payment_offline_review' => 'On', 'ai_multimodal_understanding' => 'On'],
            'en_US',
            '22222222-2222-4222-8222-222222222222'
        );
    }
}
