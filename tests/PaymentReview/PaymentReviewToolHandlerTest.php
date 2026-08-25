<?php

declare(strict_types=1);

namespace Veyra\Tests\PaymentReview;

use PHPUnit\Framework\TestCase;
use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContext;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Application\LockManager;
use Veyra\Media\Tool\MediaToolHandler;
use Veyra\PaymentReview\Application\PaymentReviewService;
use Veyra\PaymentReview\Tool\PaymentReviewToolHandler;
use Veyra\Shared\Domain\SecretDigester;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Media\Support\InMemoryAttachmentRepository;
use Veyra\Tests\PaymentReview\Support\FakePaymentReviewAuthority;
use Veyra\Tests\PaymentReview\Support\InMemoryPaymentReviewRepository;
use Veyra\Tests\PaymentReview\Support\InMemoryLockRepository;
use Veyra\Tests\Support\FrozenClock;
use Veyra\Tests\Support\InMemoryIdempotencyRepository;

final class PaymentReviewToolHandlerTest extends TestCase
{
    public function testSensitiveSubmissionToolsAreNotModelVisibleAndFailClosed(): void
    {
        $handler = $this->handler();
        $byName = [];
        foreach ($handler->definitions() as $definition) {
            $byName[$definition->name] = $definition;
        }
        self::assertFalse($byName['payment_review.submit_confirmed_evidence']->modelVisible);
        self::assertFalse($byName['payment_review.resubmit_evidence']->modelVisible);

        $call = new ToolCall('call-submit', 'payment_review.submit_confirmed_evidence', '1.0.0', [
            'review_id' => '33333333-3333-4333-8333-333333333333',
            'expected_version' => 1,
            'state_hash' => str_repeat('a', 64),
            'confirmation_token' => str_repeat('t', 32),
            'idempotency_key' => 'payment-submit-0001',
        ]);
        $result = $handler->execute($call, $this->context());
        self::assertSame('blocked', $result->status);
        self::assertSame('payment_review_sensitive_action_gate_not_integrated', $result->code);
    }

    public function testFailedIdempotentAttemptNeverReplaysAsSuccess(): void
    {
        $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
        $actors = new FoundationActorMapper();
        $idempotency = new IdempotencyService(
            new InMemoryIdempotencyRepository(),
            new SecretDigester(str_repeat('k', 32)),
            $clock
        );
        $handler = new PaymentReviewToolHandler(
            new PaymentReviewService(
                new InMemoryPaymentReviewRepository(),
                new InMemoryAttachmentRepository(),
                new FakePaymentReviewAuthority('payment_review_policy_unconfigured'),
                $actors,
                $clock
            ),
            $idempotency,
            $actors,
            new LockManager(new InMemoryLockRepository(), new SecretDigester(str_repeat('l', 32)), $clock)
        );
        $arguments = [
            'order_id' => 7001,
            'expected_version' => 0,
            'note' => 'Payment sent.',
            'idempotency_key' => 'payment-draft-0001',
        ];
        $first = $handler->execute(new ToolCall('call-1', 'payment_review.create_or_update_draft', '1.0.0', $arguments), $this->context());
        $second = $handler->execute(new ToolCall('call-2', 'payment_review.create_or_update_draft', '1.0.0', $arguments), $this->context());
        self::assertSame('blocked', $first->status);
        self::assertSame('blocked', $second->status);
        self::assertNotSame('succeeded', $second->status);
    }

    public function testProtectedAttachmentToolNeverExposesStorageKey(): void
    {
        $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
        $repository = new InMemoryAttachmentRepository();
        $attachment = \Veyra\Media\Domain\Attachment::quarantined(
            new \Veyra\Infrastructure\Database\Repository\ActorScope('customer', 'wp-user-42'),
            '11111111-1111-4111-8111-111111111111',
            null,
            'payment_evidence',
            'private_fs',
            '2026/08/' . str_repeat('a', 48) . '.png',
            'image/png',
            128,
            str_repeat('b', 64),
            $clock->now(),
            86400
        )->withScanResult('clean', $clock->now());
        self::assertTrue($repository->insert($attachment));
        $handler = new MediaToolHandler($repository, new FoundationActorMapper(), $clock);
        $result = $handler->execute(
            new ToolCall('call-media', 'media.get_protected_attachment', '1.0.0', ['attachment_id' => $attachment->id]),
            $this->context()
        );
        self::assertSame('succeeded', $result->status);
        self::assertArrayNotHasKey('storage_key', $result->data['attachment']);
    }

    private function handler(): PaymentReviewToolHandler
    {
        $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
        $actors = new FoundationActorMapper();
        return new PaymentReviewToolHandler(
            new PaymentReviewService(
                new InMemoryPaymentReviewRepository(),
                new InMemoryAttachmentRepository(),
                new FakePaymentReviewAuthority(),
                $actors,
                $clock
            ),
            new IdempotencyService(new InMemoryIdempotencyRepository(), new SecretDigester(str_repeat('k', 32)), $clock),
            $actors,
            new LockManager(new InMemoryLockRepository(), new SecretDigester(str_repeat('l', 32)), $clock)
        );
    }

    private function context(): ToolContext
    {
        return new ToolContext(
            'customer',
            'wp-user-42',
            42,
            null,
            '11111111-1111-4111-8111-111111111111',
            [],
            ['payment_offline_review' => 'On', 'ai_multimodal_understanding' => 'On'],
            'en_US',
            '22222222-2222-4222-8222-222222222222'
        );
    }
}
