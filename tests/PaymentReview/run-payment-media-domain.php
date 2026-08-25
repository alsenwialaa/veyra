<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContext;
use Veyra\Confirmation\Application\IdempotencyRepository;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Application\LockManager;
use Veyra\Confirmation\Domain\IdempotencyRecord;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Media\Domain\Attachment;
use Veyra\Media\Domain\StoredObject;
use Veyra\Media\Domain\ValidatedFile;
use Veyra\Media\Application\FileValidator;
use Veyra\Media\Application\ImageReencoder;
use Veyra\Media\Application\ProtectedAttachmentAccessService;
use Veyra\Media\Application\ProtectedStorage;
use Veyra\Media\Application\ProtectedUploadService;
use Veyra\Media\Infrastructure\FailClosedMalwareScanner;
use Veyra\Media\Infrastructure\ProtectedStorageFactory;
use Veyra\Media\Tool\MediaToolHandler;
use Veyra\PaymentReview\Application\PaymentReviewService;
use Veyra\PaymentReview\Tool\PaymentReviewToolHandler;
use Veyra\Shared\Domain\SecretDigester;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Media\Support\InMemoryAttachmentRepository;
use Veyra\Tests\Media\Support\OwnedConversationStore;
use Veyra\Tests\PaymentReview\Support\FakePaymentReviewAuthority;
use Veyra\Tests\PaymentReview\Support\InMemoryPaymentReviewRepository;
use Veyra\Tests\PaymentReview\Support\InMemoryLockRepository;
use Veyra\Tests\Support\FrozenClock;
use Veyra\Tests\Support\InMemoryIdempotencyRepository;

$passed = 0;
$failed = 0;
$scenario = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        ++$passed;
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$context = static fn (string $actorId = 'wp-user-42', int $userId = 42): ToolContext => new ToolContext(
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

$scenario('clean scan is required and storage key stays private', static function () use ($assert): void {
    $now = UtcInstant::fromDatabase('2026-08-24 10:00:00');
    $attachment = Attachment::quarantined(
        new ActorScope('customer', 'wp-user-42'),
        '11111111-1111-4111-8111-111111111111',
        'msg_' . str_repeat('a', 32),
        'payment_evidence',
        'private_fs',
        '2026/08/' . str_repeat('b', 48) . '.png',
        'image/png',
        100,
        str_repeat('c', 64),
        $now,
        86400
    );
    $assert(!$attachment->isUsable($now), 'A pending attachment was usable.');
    $clean = $attachment->withScanResult('clean', $now);
    $assert($clean->isUsable($now), 'A clean attachment was not usable.');
    $assert(!$clean->isUsable($now->addSeconds(86400)), 'An expired attachment remained usable.');
    $assert(!array_key_exists('storage_key', $clean->safeMetadata($now)), 'Storage key leaked into safe metadata.');
});

$scenario('media metadata is actor scoped', static function () use ($assert, $context): void {
    $now = UtcInstant::fromDatabase('2026-08-24 10:00:00');
    $repository = new InMemoryAttachmentRepository();
    $attachment = Attachment::quarantined(
        new ActorScope('customer', 'wp-user-42'),
        '11111111-1111-4111-8111-111111111111',
        null,
        'payment_evidence',
        'private_fs',
        '2026/08/' . str_repeat('d', 48) . '.png',
        'image/png',
        100,
        str_repeat('e', 64),
        $now,
        86400
    )->withScanResult('clean', $now);
    $repository->insert($attachment);
    $handler = new MediaToolHandler($repository, new FoundationActorMapper(), new FrozenClock($now));
    $call = new ToolCall('call-media', 'media.validate_upload', '1.0.0', ['attachment_id' => $attachment->id]);
    $assert($handler->execute($call, $context())->status === 'succeeded', 'Owner could not read attachment metadata.');
    $assert($handler->execute($call, $context('wp-user-43', 43))->status === 'failed', 'Cross-actor attachment read succeeded.');
});

$scenario('scanner outage keeps uploaded evidence quarantined', static function () use ($assert, $context): void {
    $path = tempnam(sys_get_temp_dir(), 'veyra-upload-');
    if (!is_string($path)) {
        throw new RuntimeException('Temporary fixture could not be created.');
    }
    file_put_contents($path, 'bounded evidence fixture');
    $checksum = hash_file('sha256', $path);
    if (!is_string($checksum)) {
        throw new RuntimeException('Fixture checksum failed.');
    }
    $validator = new class($path, $checksum) implements FileValidator {
        public function __construct(private readonly string $path, private readonly string $checksum) {}
        public function validate(string $path, string $claimedMimeType): ValidatedFile
        {
            return new ValidatedFile($this->path, 'application/pdf', (int) filesize($this->path), $this->checksum);
        }
    };
    $images = new class implements ImageReencoder {
        public function reencode(string $sourcePath, string $mimeType): string
        {
            throw new RuntimeException('Unexpected image path.');
        }
    };
    $storage = new class implements ProtectedStorage {
        /** @var array<string, string> */
        private array $objects = [];
        public function store(string $sourcePath, string $mimeType, string $checksumSha256): StoredObject
        {
            $key = '2026/08/' . str_repeat('f', 48) . '.pdf';
            $this->objects[$key] = (string) file_get_contents($sourcePath);
            return new StoredObject('private_fs', $key, strlen($this->objects[$key]), $checksumSha256);
        }
        public function open(string $key)
        {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $this->objects[$key] ?? '');
            rewind($stream);
            return $stream;
        }
        public function delete(string $key): bool
        {
            unset($this->objects[$key]);
            return true;
        }
    };
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $service = new ProtectedUploadService(
        $validator,
        $images,
        new FailClosedMalwareScanner(),
        $storage,
        new InMemoryAttachmentRepository(),
        new IdempotencyService(new InMemoryIdempotencyRepository(), new SecretDigester(str_repeat('k', 32)), $clock),
        new FoundationActorMapper(),
        $clock,
        new OwnedConversationStore('11111111-1111-4111-8111-111111111111', 'customer', 'wp-user-42'),
        new LockManager(new InMemoryLockRepository(), new SecretDigester(str_repeat('l', 32)), $clock),
        2592000
    );
    try {
        $first = $service->accept($context(), $path, 'application/pdf', 'payment_evidence', 'upload-request-0001');
        $second = $service->accept($context(), $path, 'application/pdf', 'payment_evidence', 'upload-request-0001');
        $assert($first->status === 'blocked' && $first->attachment?->scanStatus === 'unavailable', 'Scanner outage did not quarantine evidence.');
        $assert($second->status !== 'succeeded', 'Failed upload replayed as success.');
    } finally {
        @unlink($path);
    }
});

$scenario('protected storage rejects every path beneath the server document root', static function () use ($assert): void {
    $documentRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyra-document-root-' . bin2hex(random_bytes(8));
    $protectedPath = $documentRoot . DIRECTORY_SEPARATOR . 'private-evidence';
    if (!mkdir($documentRoot, 0700, true) && !is_dir($documentRoot)) {
        throw new RuntimeException('Document-root fixture could not be created.');
    }
    if (!defined(ProtectedStorageFactory::PATH_CONSTANT)) {
        define(ProtectedStorageFactory::PATH_CONSTANT, $protectedPath);
    }
    $_SERVER['DOCUMENT_ROOT'] = $documentRoot;
    try {
        $assert(ProtectedStorageFactory::storage() === null, 'Storage beneath DOCUMENT_ROOT was accepted.');
    } finally {
        @rmdir($protectedPath);
        @rmdir($documentRoot);
        unset($_SERVER['DOCUMENT_ROOT']);
    }
});

$scenario('verified protected media never spills plaintext to generic temporary storage', static function () use ($assert): void {
    $sourceText = file_get_contents(dirname(__DIR__, 2) . '/src/Media/Application/ProtectedAttachmentAccessService.php');
    $assert(is_string($sourceText), 'Protected attachment access source could not be inspected.');
    $assert(str_contains($sourceText, "fopen('php://memory', 'w+b')"), 'Verified media is not held in a memory-only stream.');
    $assert(!str_contains($sourceText, 'php://temp/maxmemory:'), 'Verified media can still spill into generic temporary storage.');

    // Exercise a payload larger than the former 2 MiB spill threshold while
    // staying below the service's authoritative 10 MiB verification bound.
    $payload = str_repeat('verified-payment-evidence-', 131073);
    $source = fopen('php://memory', 'w+b');
    $assert(is_resource($source), 'Large protected-media source fixture could not be opened.');
    fwrite($source, $payload);
    rewind($source);

    $reflection = new ReflectionClass(ProtectedAttachmentAccessService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('verifiedStream');
    $method->setAccessible(true);
    $verified = $method->invoke($service, $source, strlen($payload), hash('sha256', $payload));
    $metadata = stream_get_meta_data($verified);
    $assert(($metadata['uri'] ?? null) === 'php://memory', 'Large verified media did not remain in process memory.');
    $assert(hash('sha256', (string) stream_get_contents($verified)) === hash('sha256', $payload), 'Verified media bytes changed in memory.');
    fclose($verified);
});

$scenario('payment review draft is exact and versioned', static function () use ($assert, $context): void {
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $service = new PaymentReviewService(
        new InMemoryPaymentReviewRepository(),
        new InMemoryAttachmentRepository(),
        new FakePaymentReviewAuthority(),
        new FoundationActorMapper(),
        $clock
    );
    $created = $service->createOrUpdateDraft($context(), [
        'order_id' => 7001,
        'expected_version' => 0,
        'note' => 'Paid by bank transfer.',
    ]);
    $assert($created->status === 'succeeded' && $created->review !== null, 'Draft creation failed.');
    $updated = $service->createOrUpdateDraft($context(), [
        'order_id' => 7001,
        'review_id' => $created->review->id,
        'expected_version' => 1,
        'reference' => 'REF-42',
    ]);
    $assert($updated->status === 'succeeded' && $updated->review?->version === 2, 'Versioned update failed.');
    $stale = $service->createOrUpdateDraft($context(), [
        'order_id' => 7001,
        'review_id' => $created->review->id,
        'expected_version' => 1,
        'reference' => 'STALE',
    ]);
    $assert($stale->status === 'stale', 'Stale update was not rejected.');
});

$scenario('payment review transfer time requires an absolute valid instant', static function () use ($assert, $context): void {
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $service = static fn (): PaymentReviewService => new PaymentReviewService(
        new InMemoryPaymentReviewRepository(),
        new InMemoryAttachmentRepository(),
        new FakePaymentReviewAuthority(),
        new FoundationActorMapper(),
        $clock
    );

    $relative = $service()->createOrUpdateDraft($context(), [
        'order_id' => 7001,
        'expected_version' => 0,
        'transferred_at' => 'tomorrow',
    ]);
    $timezoneLess = $service()->createOrUpdateDraft($context(), [
        'order_id' => 7001,
        'expected_version' => 0,
        'transferred_at' => '2026-08-24T12:30:00',
    ]);
    $invalidCalendar = $service()->createOrUpdateDraft($context(), [
        'order_id' => 7001,
        'expected_version' => 0,
        'transferred_at' => '2026-02-30T12:30:00+03:00',
    ]);
    $absolute = $service()->createOrUpdateDraft($context(), [
        'order_id' => 7001,
        'expected_version' => 0,
        'transferred_at' => '2026-08-24T12:30:00+03:00',
    ]);

    $assert($relative->code === 'payment_review_transfer_time_invalid', 'A relative payment time was interpreted in server time.');
    $assert($timezoneLess->code === 'payment_review_transfer_time_invalid', 'A timezone-less payment time was interpreted in server time.');
    $assert($invalidCalendar->code === 'payment_review_transfer_time_invalid', 'An invalid calendar instant was normalized silently.');
    $assert($absolute->status === 'succeeded', 'A valid absolute payment time was rejected.');
    $assert(($absolute->review?->evidence['customer_fields']['transferred_at'] ?? null) === '2026-08-24T09:30:00Z', 'Absolute payment time was not normalized to UTC.');
});

$scenario('failed idempotent attempt never replays as success', static function () use ($assert, $context): void {
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $actors = new FoundationActorMapper();
    $handler = new PaymentReviewToolHandler(
        new PaymentReviewService(
            new InMemoryPaymentReviewRepository(),
            new InMemoryAttachmentRepository(),
            new FakePaymentReviewAuthority('payment_review_policy_unconfigured'),
            $actors,
            $clock
        ),
        new IdempotencyService(new InMemoryIdempotencyRepository(), new SecretDigester(str_repeat('k', 32)), $clock),
        $actors,
        new LockManager(new InMemoryLockRepository(), new SecretDigester(str_repeat('l', 32)), $clock)
    );
    $arguments = ['order_id' => 7001, 'expected_version' => 0, 'note' => 'Paid.', 'idempotency_key' => 'review-draft-0001'];
    $first = $handler->execute(new ToolCall('call-1', 'payment_review.create_or_update_draft', '1.0.0', $arguments), $context());
    $second = $handler->execute(new ToolCall('call-2', 'payment_review.create_or_update_draft', '1.0.0', $arguments), $context());
    $assert($first->status === 'blocked' && $second->status === 'blocked', 'A blocked attempt replayed with the wrong status.');
});

$scenario('payment review lock denial is uncertain when its idempotency transition is not durable', static function () use ($assert, $context): void {
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $actors = new FoundationActorMapper();
    $records = new class implements IdempotencyRepository {
        private ?IdempotencyRecord $record = null;
        public function insert(IdempotencyRecord $record): bool
        {
            if ($this->record !== null) {
                return false;
            }
            $this->record = $record;
            return true;
        }
        public function find(ActorScope $actor, string $action, string $keyDigest): ?IdempotencyRecord
        {
            unset($actor, $action, $keyDigest);
            return $this->record;
        }
        public function complete(
            IdempotencyRecord $record,
            string $status,
            string $resultCode,
            array $result,
            bool $retrySafe,
            UtcInstant $completedAt
        ): bool {
            unset($record, $status, $resultCode, $result, $retrySafe, $completedAt);
            return false;
        }
    };
    $locks = new LockManager(new InMemoryLockRepository(), new SecretDigester(str_repeat('l', 32)), $clock);
    $held = $locks->acquire(
        'payment-review-draft:' . hash('sha256', 'customer:wp-user-42') . ':7001',
        CorrelationId::generate(),
        30
    );
    $assert($held !== null, 'Payment-review contention fixture did not acquire its lease.');
    $handler = new PaymentReviewToolHandler(
        new PaymentReviewService(
            new InMemoryPaymentReviewRepository(),
            new InMemoryAttachmentRepository(),
            new FakePaymentReviewAuthority(),
            $actors,
            $clock
        ),
        new IdempotencyService($records, new SecretDigester(str_repeat('k', 32)), $clock),
        $actors,
        $locks
    );
    $result = $handler->execute(new ToolCall('call-transition-uncertain', 'payment_review.create_or_update_draft', '1.0.0', [
        'order_id' => 7001,
        'expected_version' => 0,
        'note' => 'Bounded evidence note.',
        'idempotency_key' => 'review-transition-0001',
    ]), $context());

    $assert($result->status === 'uncertain', 'An unpersisted lock-denial transition was reported as terminal.');
    $assert($result->code === 'payment_review_idempotency_transition_uncertain', 'The payment-review uncertainty reason was not explicit.');
    $assert($result->retrySafe === false, 'An uncertain payment-review transition was marked retry-safe.');
});

$scenario('submission and resubmission are hidden and fail closed', static function () use ($assert, $context): void {
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $actors = new FoundationActorMapper();
    $handler = new PaymentReviewToolHandler(
        new PaymentReviewService(new InMemoryPaymentReviewRepository(), new InMemoryAttachmentRepository(), new FakePaymentReviewAuthority(), $actors, $clock),
        new IdempotencyService(new InMemoryIdempotencyRepository(), new SecretDigester(str_repeat('k', 32)), $clock),
        $actors,
        new LockManager(new InMemoryLockRepository(), new SecretDigester(str_repeat('l', 32)), $clock)
    );
    $definitions = [];
    foreach ($handler->definitions() as $definition) {
        $definitions[$definition->name] = $definition;
    }
    $assert(!$definitions['payment_review.submit_confirmed_evidence']->modelVisible, 'Submission is model-visible.');
    $assert(!$definitions['payment_review.resubmit_evidence']->modelVisible, 'Resubmission is model-visible.');
    $result = $handler->execute(new ToolCall('call-submit', 'payment_review.submit_confirmed_evidence', '1.0.0', [
        'review_id' => '33333333-3333-4333-8333-333333333333',
        'expected_version' => 1,
        'state_hash' => str_repeat('a', 64),
        'confirmation_token' => str_repeat('t', 32),
        'idempotency_key' => 'review-submit-0001',
    ]), $context());
    $assert($result->status === 'blocked', 'Sensitive submission did not fail closed.');
});

fwrite(STDOUT, sprintf("Payment/media domain scenarios: %d passed, %d failed\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
