<?php

declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\Conversation\Application\ConversationStateUpdater;
use Veyra\Conversation\Application\ConversationStore;
use Veyra\Conversation\Domain\ConversationFocus;
use Veyra\Conversation\Persistence\WpdbConversationStore;

final class FocusCaptureStore implements ConversationStore
{
    public ?ConversationFocus $state = null;

    public function createConversation(string $actorType, string $actorId, ?int $userId, ?string $guestSessionId): string { throw new LogicException('unused'); }
    public function currentOwnedConversation(string $actorType, string $actorId): ?array { return []; }
    public function getOwnedConversation(string $conversationId, string $actorType, string $actorId): ?array { return []; }
    public function recentVisibleMessages(string $conversationId, string $actorType, string $actorId, int $limit): array { return []; }
    public function visibleMessage(string $conversationId, string $actorType, string $actorId, string $messageId): ?array { return null; }
    public function journeys(string $conversationId, string $actorType, string $actorId): array { return []; }
    public function focus(string $conversationId, string $actorType, string $actorId): ?ConversationFocus { return $this->state; }
    public function memory(string $conversationId, string $actorType, string $actorId): array { return []; }
    public function summary(string $conversationId, string $actorType, string $actorId): array { return []; }
    public function appendVisibleMessage(string $conversationId, string $actorType, string $actorId, string $senderType, string $text, array $renderPayload, array $evidence, string $correlationId): string { throw new LogicException('unused'); }
    public function saveFocus(string $conversationId, string $actorType, string $actorId, ConversationFocus $focus, string $expectedVersion): bool
    {
        if (($this->state?->version ?? '0') !== $expectedVersion) {
            return false;
        }
        $this->state = $focus;
        return true;
    }
    public function consumePendingQuestion(string $conversationId, string $actorType, string $actorId, string $questionId, string $expectedFocusVersion, int $expectedQuestionVersion, string $customerMessageId, array $validatedBinding): array { return ['consumed' => false, 'code' => 'unused', 'binding_id' => null]; }
    public function saveMemory(string $conversationId, string $actorType, string $actorId, array $memory, string $sourceMessageId): bool { return false; }
}

final class FocusWpdbFixture
{
    public string $prefix = 'wp_';
    public bool $owner = true;
    public int|false $updateResult = 1;
    /** @var array<string, mixed>|null */
    public ?array $focusRow = null;
    /** @var list<string> */
    public array $transactions = [];
    public int $focusWrites = 0;

    public function prepare(string $query, mixed ...$arguments): string
    {
        foreach ($arguments as $argument) {
            $replacement = is_int($argument) ? (string) $argument : "'" . str_replace("'", "''", (string) $argument) . "'";
            $query = preg_replace('/%[sd]/', $replacement, $query, 1) ?? $query;
        }
        return $query;
    }

    public function query(string $query): int|false
    {
        if (in_array($query, ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true)) {
            $this->transactions[] = $query;
            return 1;
        }
        return false;
    }

    public function get_row(string $query, mixed $format = null): ?array
    {
        if (str_contains($query, 'veyra_conversations')) {
            return $this->owner ? ['public_id' => 'conversation-000001'] : null;
        }
        if (str_contains($query, 'veyra_conversation_focus')) {
            return $this->focusRow;
        }
        return null;
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data, array $format = []): int|false
    {
        if (!str_ends_with($table, 'veyra_conversation_focus')) {
            return false;
        }
        ++$this->focusWrites;
        $this->focusRow = ['id' => 11] + $data;
        return 1;
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $where */
    public function update(string $table, array $data, array $where): int|false
    {
        if (!str_ends_with($table, 'veyra_conversation_focus')) {
            return false;
        }
        ++$this->focusWrites;
        if ($this->updateResult === 1 && $this->focusRow !== null) {
            $this->focusRow = array_merge($this->focusRow, $data);
        }
        return $this->updateResult;
    }
}

$passed = 0;
$failed = 0;
$test = static function (string $name, callable $scenario) use (&$passed, &$failed): void {
    try {
        $scenario();
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

$validFocus = static fn (): array => [
    'foreground_journey_id' => 'journey-00000001',
    'focused_resources' => ['product' => 'product-00000001'],
    'pending_question' => [
        'step_id' => 'choose-size',
        'answer_schema' => ['type' => 'string', 'enum' => ['small', 'large']],
        'allowed_choice_ids' => ['size-small', 'size-large'],
        'sensitivity' => 'informational',
        'ttl_seconds' => 900,
        'dependency_versions' => ['cart' => 'cart-version-1'],
    ],
    'unresolved_references' => ['reference-000001', 'reference-000002'],
];
$authorized = [
    'journey' => ['journey-00000001' => true],
    'product' => ['product-00000001' => true],
];

$test('strict focus proposal persists an exact pending journey and unresolved-reference set', static function () use ($assert, $validFocus, $authorized): void {
    $store = new FocusCaptureStore();
    $outcome = (new ConversationStateUpdater($store))->applyValidatedProposal(
        'conversation-000001', 'guest', 'guest-00000001',
        'message-00000001', 'message-00000001',
        ['focus' => $validFocus()],
        $authorized
    );
    $assert($outcome['focus_updated'], 'Valid focus was not persisted.');
    $assert($store->state?->unresolvedReferences === ['reference-000001', 'reference-000002'], 'Unresolved references changed during validation.');
    $assert($store->state?->pendingQuestion?->journeyId === 'journey-00000001', 'Pending Question lost its foreground journey binding.');
});

$test('pending questions without a foreground journey are rejected', static function () use ($assert, $validFocus, $authorized): void {
    $proposal = $validFocus();
    $proposal['foreground_journey_id'] = null;
    $store = new FocusCaptureStore();
    $outcome = (new ConversationStateUpdater($store))->applyValidatedProposal(
        'conversation-000001', 'guest', 'guest-00000001',
        'message-00000001', 'message-00000001', ['focus' => $proposal], $authorized
    );
    $assert(!$outcome['focus_updated'] && in_array('focus_update_rejected', $outcome['warnings'], true), 'Journey-less Pending Question was accepted.');
});

$test('foreground journey ids cannot exceed the char-36 persistence contract', static function () use ($assert, $validFocus): void {
    $overlongJourneyId = 'journey-' . str_repeat('x', 30);
    $proposal = $validFocus();
    $proposal['foreground_journey_id'] = $overlongJourneyId;
    $store = new FocusCaptureStore();
    $outcome = (new ConversationStateUpdater($store))->applyValidatedProposal(
        'conversation-000001', 'guest', 'guest-00000001',
        'message-00000001', 'message-00000001', ['focus' => $proposal],
        ['journey' => [$overlongJourneyId => true], 'product' => ['product-00000001' => true]]
    );
    $assert(
        !$outcome['focus_updated'] && in_array('focus_update_rejected', $outcome['warnings'], true),
        'An authorized journey id that persistence would truncate was accepted.'
    );
});

$test('invalid sensitivity and coerced TTL fail closed', static function () use ($assert, $validFocus, $authorized): void {
    foreach ([
        ['sensitivity' => 'unknown'],
        ['ttl_seconds' => '900'],
    ] as $change) {
        $proposal = $validFocus();
        $proposal['pending_question'] = array_merge($proposal['pending_question'], $change);
        $store = new FocusCaptureStore();
        $outcome = (new ConversationStateUpdater($store))->applyValidatedProposal(
            'conversation-000001', 'guest', 'guest-00000001',
            'message-00000001', 'message-00000001', ['focus' => $proposal], $authorized
        );
        $assert(!$outcome['focus_updated'], 'Malformed Pending Question field was normalized instead of rejected.');
    }
});

$test('unresolved references reject wrong types, duplicates, and unbounded lists', static function () use ($assert, $validFocus, $authorized): void {
    foreach ([
        ['reference-000001', 7],
        ['reference-000001', 'reference-000001'],
        array_map(static fn (int $index): string => 'reference-' . str_pad((string) $index, 6, '0', STR_PAD_LEFT), range(1, 11)),
    ] as $references) {
        $proposal = $validFocus();
        $proposal['unresolved_references'] = $references;
        $store = new FocusCaptureStore();
        $outcome = (new ConversationStateUpdater($store))->applyValidatedProposal(
            'conversation-000001', 'guest', 'guest-00000001',
            'message-00000001', 'message-00000001', ['focus' => $proposal], $authorized
        );
        $assert(!$outcome['focus_updated'], 'Malformed unresolved-reference set was normalized instead of rejected.');
    }
});

$test('wpdb focus storage round-trips unresolved references under an actor-owned transaction', static function () use ($assert): void {
    $database = new FocusWpdbFixture();
    $store = new WpdbConversationStore($database);
    $focus = new ConversationFocus(
        '1', null, [], null, ['reference-000001', 'reference-000002'],
        'message-00000001', new DateTimeImmutable('2026-08-25T00:00:00Z')
    );
    $assert($store->saveFocus('conversation-000001', 'guest', 'guest-00000001', $focus, '0'), 'Actor-owned focus insert failed.');
    $assert(($database->focusRow['unresolved_references_json'] ?? null) === '["reference-000001","reference-000002"]', 'Unresolved references were not durably encoded.');
    $loaded = $store->focus('conversation-000001', 'guest', 'guest-00000001');
    $assert($loaded?->unresolvedReferences === $focus->unresolvedReferences, 'Persisted unresolved references did not round-trip.');
    $assert($database->transactions === ['START TRANSACTION', 'COMMIT'], 'Focus persistence did not use one committed transaction.');
});

$test('wpdb focus storage rejects foreign actors and zero-row compare-and-set updates', static function () use ($assert): void {
    $focus = new ConversationFocus(
        '1', null, [], null, ['reference-000001'],
        'message-00000001', new DateTimeImmutable('2026-08-25T00:00:00Z')
    );
    $foreign = new FocusWpdbFixture();
    $foreign->owner = false;
    $assert(!(new WpdbConversationStore($foreign))->saveFocus('conversation-000001', 'guest', 'guest-00000001', $focus, '0'), 'Foreign actor created a focus row.');
    $assert($foreign->focusWrites === 0 && $foreign->transactions === ['START TRANSACTION', 'ROLLBACK'], 'Foreign focus write was not stopped before persistence.');

    $conflict = new FocusWpdbFixture();
    $conflict->focusRow = [
        'id' => 11,
        'public_id' => 'focus-000000001',
        'version' => 1,
        'pending_question_id' => null,
    ];
    $conflict->updateResult = 0;
    $next = new ConversationFocus(
        '2', null, [], null, ['reference-000002'],
        'message-00000002', new DateTimeImmutable('2026-08-25T00:01:00Z')
    );
    $assert(!(new WpdbConversationStore($conflict))->saveFocus('conversation-000001', 'guest', 'guest-00000001', $next, '1'), 'Zero-row focus compare-and-set was reported as success.');
    $assert(array_slice($conflict->transactions, -2) === ['START TRANSACTION', 'ROLLBACK'], 'Zero-row focus compare-and-set was committed.');
});

$test('malformed stored unresolved references invalidate the focus projection', static function () use ($assert): void {
    $database = new FocusWpdbFixture();
    $database->focusRow = [
        'id' => 11,
        'public_id' => 'focus-000000001',
        'conversation_id' => 'conversation-000001',
        'actor_type' => 'guest',
        'actor_id' => 'guest-00000001',
        'actor_key_hash' => hash('sha256', 'guest:guest-00000001'),
        'foreground_journey_id' => null,
        'pending_question_id' => null,
        'focused_resources_json' => '{}',
        'unresolved_references_json' => '["reference-000001","reference-000001"]',
        'source_message_id' => 'message-00000001',
        'version' => 1,
        'updated_at' => '2026-08-25 00:00:00',
    ];
    $assert((new WpdbConversationStore($database))->focus('conversation-000001', 'guest', 'guest-00000001') === null, 'Corrupt stored unresolved references were silently repaired.');
});

fwrite(STDOUT, "Conversation Focus scenarios: {$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
