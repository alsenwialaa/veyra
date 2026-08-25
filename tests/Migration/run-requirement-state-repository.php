<?php

declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

/** Deterministic wpdb double for the requirement-head SQL contract. */
final class wpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';

    /** @var array<string,array<string,mixed>> */
    public array $rows = [];

    /** @var array<string,array{actor_type:string,actor_id:string,actor_key_hash:string}> */
    public array $conversationOwners = [];

    /** @var array<string,true> */
    public array $customerMessages = [];

    /** @var array<string,array{sql:string,args:list<mixed>}> */
    public array $prepared = [];

    public bool $failNextQuery = false;

    private int $sequence = 0;

    public function ownConversation(
        string $conversationId,
        string $actorType,
        string $actorId,
        string $sourceMessageId
    ): void {
        $hash = hash('sha256', $actorType . ':' . $actorId);
        $this->conversationOwners[$conversationId] = [
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_key_hash' => $hash,
        ];
        $this->customerMessages[$this->messageKey($conversationId, $actorType, $actorId, $sourceMessageId)] = true;
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
        $token = 'prepared-' . ++$this->sequence;
        $this->prepared[$token] = ['sql' => $query, 'args' => array_values($arguments)];

        return $token;
    }

    public function get_row(string $token, mixed $output = null): ?array
    {
        $this->last_error = '';
        $prepared = $this->prepared[$token] ?? null;
        if (!is_array($prepared) || !str_contains($prepared['sql'], 'SELECT conversation_id')) {
            $this->last_error = 'unsupported read';
            return null;
        }
        [$conversationId, $actorType, $actorId, $actorHash] = $prepared['args'];
        $row = $this->rows[(string) $conversationId] ?? null;
        if (!is_array($row)
            || $row['actor_type'] !== $actorType
            || $row['actor_id'] !== $actorId
            || $row['actor_key_hash'] !== $actorHash
        ) {
            return null;
        }

        return $row;
    }

    public function query(string $token): int|false
    {
        $this->last_error = '';
        if ($this->failNextQuery) {
            $this->failNextQuery = false;
            $this->last_error = 'injected database failure';
            return false;
        }
        $prepared = $this->prepared[$token] ?? null;
        if (!is_array($prepared)) {
            $this->last_error = 'unprepared query';
            return false;
        }
        $arguments = $prepared['args'];
        if (str_contains($prepared['sql'], 'INSERT INTO wp_veyra_requirement_states')) {
            [
                $publicId,
                $conversationId,
                $actorType,
                $actorId,
                $actorHash,
                $stateJson,
                $stateHash,
                $version,
                $lastSource,
                $createdAt,
                $updatedAt,
            ] = $arguments;
            $expectedConversationId = $arguments[11] ?? null;
            $expectedActorType = $arguments[12] ?? null;
            $expectedActorId = $arguments[13] ?? null;
            $expectedActorHash = $arguments[14] ?? null;
            $expectedSourceMessageId = $arguments[15] ?? null;
            $owner = $this->conversationOwners[(string) $expectedConversationId] ?? null;
            if (!is_array($owner)
                || $owner['actor_type'] !== $expectedActorType
                || $owner['actor_id'] !== $expectedActorId
                || $owner['actor_key_hash'] !== $expectedActorHash
                || !isset($this->customerMessages[$this->messageKey(
                    (string) $expectedConversationId,
                    (string) $expectedActorType,
                    (string) $expectedActorId,
                    (string) $expectedSourceMessageId
                )])
            ) {
                return 0;
            }
            if (isset($this->rows[(string) $conversationId])) {
                return 0;
            }
            $this->rows[(string) $conversationId] = [
                'public_id' => $publicId,
                'conversation_id' => $conversationId,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_key_hash' => $actorHash,
                'state_json' => $stateJson,
                'state_hash' => $stateHash,
                'version' => $version,
                'last_source_message_id' => $lastSource,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ];
            return 1;
        }
        if (str_contains($prepared['sql'], 'UPDATE wp_veyra_requirement_states')) {
            [
                $stateJson,
                $nextHash,
                $nextVersion,
                $lastSource,
                $updatedAt,
                $conversationId,
                $actorType,
                $actorId,
                $actorHash,
                $expectedVersion,
                $expectedHash,
            ] = $arguments;
            $row = $this->rows[(string) $conversationId] ?? null;
            if (!is_array($row)
                || $row['actor_type'] !== $actorType
                || $row['actor_id'] !== $actorId
                || $row['actor_key_hash'] !== $actorHash
                || (int) $row['version'] !== (int) $expectedVersion
                || $row['state_hash'] !== $expectedHash
            ) {
                return 0;
            }
            $row['state_json'] = $stateJson;
            $row['state_hash'] = $nextHash;
            $row['version'] = $nextVersion;
            $row['last_source_message_id'] = $lastSource;
            $row['updated_at'] = $updatedAt;
            $this->rows[(string) $conversationId] = $row;
            return 1;
        }

        $this->last_error = 'unsupported write';
        return false;
    }

    private function messageKey(string $conversationId, string $actorType, string $actorId, string $messageId): string
    {
        return hash('sha256', $conversationId . "\0" . $actorType . "\0" . $actorId . "\0" . $messageId);
    }
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\Infrastructure\Database\TableNames;
use Veyra\Requirements\Domain\RequirementCriterion;
use Veyra\Requirements\Domain\RequirementState;
use Veyra\Requirements\Infrastructure\WpdbRequirementStateRepository;

$failed = 0;
$assert = static function (bool $condition, string $message) use (&$failed): void {
    if ($condition) {
        return;
    }
    ++$failed;
    fwrite(STDERR, "FAIL {$message}\n");
};

$conversationId = '11111111-1111-4111-8111-111111111111';
$actorType = 'customer';
$actorId = 'wp-user-7';
$sourceOne = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$sourceTwo = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$database = new wpdb();
$database->ownConversation($conversationId, $actorType, $actorId, $sourceOne);
$database->ownConversation($conversationId, $actorType, $actorId, $sourceTwo);
$repository = new WpdbRequirementStateRepository($database, new TableNames('wp_'));
$empty = RequirementState::empty($conversationId, $actorType, $actorId, '2026-08-24T10:00:00Z');
$firstCriterion = RequirementCriterion::proposed(
    'budget',
    'max',
    100,
    'hard',
    'active',
    [
        'message_id' => $sourceOne,
        'excerpt_sha256' => hash('sha256', 'under 100'),
        'excerpt_offset_bytes' => 7,
        'excerpt_length_bytes' => 9,
        'source_kind' => 'customer_visible_message',
    ],
    [],
    '2026-08-24T10:00:00Z'
);
$first = $empty->next([$firstCriterion], $sourceOne, '2026-08-24T10:00:00Z');

$assert($repository->compareAndSwap($empty, $first), 'unique empty-head insert did not succeed.');
$assert(!$repository->compareAndSwap($empty, $first), 'duplicate empty-head insert was not rejected atomically.');
$loaded = $repository->loadOwned($conversationId, $actorType, $actorId);
$assert($loaded !== null, 'owned requirement head was not loaded.');
$assert($loaded?->resourceVersion === 1, 'inserted resource version was not preserved.');
$assert($loaded !== null && hash_equals($first->stateHash, $loaded->stateHash), 'inserted state hash did not verify.');
$assert(
    $repository->loadOwned($conversationId, 'customer', 'wp-user-8') === null,
    'cross-actor requirement head was disclosed.'
);

if ($loaded !== null) {
    $secondCriterion = RequirementCriterion::proposed(
        'exclusion',
        'excludes',
        'wool',
        'hard',
        'active',
        [
            'message_id' => $sourceTwo,
            'excerpt_sha256' => hash('sha256', 'no wool'),
            'excerpt_offset_bytes' => 0,
            'excerpt_length_bytes' => 7,
            'source_kind' => 'customer_visible_message',
        ],
        [],
        '2026-08-24T10:01:00Z'
    );
    $second = $loaded->next([$loaded->criteria[0], $secondCriterion], $sourceTwo, '2026-08-24T10:01:00Z');
    $assert($repository->compareAndSwap($loaded, $second), 'exact expected version/hash update did not succeed.');
    $assert(!$repository->compareAndSwap($loaded, $second), 'stale expected version/hash update was accepted.');
    $reloaded = $repository->loadOwned($conversationId, $actorType, $actorId);
    $assert($reloaded?->resourceVersion === 2, 'updated resource version did not advance exactly once.');
    $assert(count($reloaded?->criteria ?? []) === 2, 'complete requirement history was not retained.');
}

$updateSql = '';
foreach ($database->prepared as $prepared) {
    if (str_contains($prepared['sql'], 'UPDATE wp_veyra_requirement_states')) {
        $updateSql = $prepared['sql'];
    }
}
foreach ([
    'conversation_id = %s',
    'actor_type = %s',
    'actor_id = %s',
    'actor_key_hash = %s',
    'version = %d',
    'state_hash = %s',
] as $predicate) {
    $assert(str_contains($updateSql, $predicate), 'CAS SQL omitted predicate ' . $predicate . '.');
}

$insertSql = '';
foreach ($database->prepared as $prepared) {
    if (str_contains($prepared['sql'], 'INSERT INTO wp_veyra_requirement_states')) {
        $insertSql = $prepared['sql'];
        break;
    }
}
foreach ([
    'FROM wp_veyra_conversations AS owned_conversation',
    'owned_conversation.public_id = %s',
    'owned_conversation.actor_type = %s',
    'owned_conversation.actor_id = %s',
    'owned_conversation.actor_key_hash = %s',
    'FROM wp_veyra_messages AS source_message',
    "source_message.sender_type = 'customer'",
    'ON DUPLICATE KEY UPDATE id = id',
] as $predicate) {
    $assert(str_contains($insertSql, $predicate), 'First-head SQL omitted ownership predicate ' . $predicate . '.');
}

$linkedConversation = '22222222-2222-4222-8222-222222222222';
$guestActorId = 'guest-link-race';
$linkedSource = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$database->ownConversation($linkedConversation, 'guest', $guestActorId, $linkedSource);
$guestEmpty = RequirementState::empty(
    $linkedConversation,
    'guest',
    $guestActorId,
    '2026-08-24T10:02:00Z'
);
$guestCriterion = RequirementCriterion::proposed(
    'category',
    'equals',
    'jackets',
    'hard',
    'active',
    [
        'message_id' => $linkedSource,
        'excerpt_sha256' => hash('sha256', 'jackets'),
        'excerpt_offset_bytes' => 0,
        'excerpt_length_bytes' => 7,
        'source_kind' => 'customer_visible_message',
    ],
    [],
    '2026-08-24T10:02:00Z'
);
$delayedGuestHead = $guestEmpty->next([$guestCriterion], $linkedSource, '2026-08-24T10:02:00Z');
// Simulate the account-link transaction committing before the delayed first
// requirement head reaches its atomic INSERT ... SELECT ownership check.
$database->ownConversation($linkedConversation, 'customer', 'wp-user-9', $linkedSource);
$assert(
    !$repository->compareAndSwap($guestEmpty, $delayedGuestHead)
        && !isset($database->rows[$linkedConversation]),
    'A delayed guest first-head insert survived the authenticated ownership re-key.'
);

$errorConversation = '33333333-3333-4333-8333-333333333333';
$errorSource = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
$database->ownConversation($errorConversation, $actorType, $actorId, $errorSource);
$errorEmpty = RequirementState::empty($errorConversation, $actorType, $actorId, '2026-08-24T10:03:00Z');
$errorCriterion = RequirementCriterion::proposed(
    'preference',
    'equals',
    ['key' => 'finish', 'value' => 'matte'],
    'soft',
    'active',
    [
        'message_id' => $errorSource,
        'excerpt_sha256' => hash('sha256', 'matte'),
        'excerpt_offset_bytes' => 0,
        'excerpt_length_bytes' => 5,
        'source_kind' => 'customer_visible_message',
    ],
    [],
    '2026-08-24T10:03:00Z'
);
$errorHead = $errorEmpty->next([$errorCriterion], $errorSource, '2026-08-24T10:03:00Z');
$database->failNextQuery = true;
$insertFailureThrown = false;
try {
    $repository->compareAndSwap($errorEmpty, $errorHead);
} catch (RuntimeException) {
    $insertFailureThrown = true;
}
$assert(
    $insertFailureThrown && !isset($database->rows[$errorConversation]),
    'A first-head SQL error was reported as an ordinary CAS miss.'
);
$assert($repository->compareAndSwap($errorEmpty, $errorHead), 'First head could not be created after the injected SQL error cleared.');
$errorSecondCriterion = RequirementCriterion::proposed(
    'category',
    'equals',
    'accessories',
    'soft',
    'active',
    [
        'message_id' => $errorSource,
        'excerpt_sha256' => hash('sha256', 'matte'),
        'excerpt_offset_bytes' => 0,
        'excerpt_length_bytes' => 5,
        'source_kind' => 'customer_visible_message',
    ],
    [],
    '2026-08-24T10:04:00Z'
);
$database->failNextQuery = true;
$updateFailureThrown = false;
try {
    $repository->compareAndSwap(
        $errorHead,
        $errorHead->next([$errorCriterion, $errorSecondCriterion], $errorSource, '2026-08-24T10:04:00Z')
    );
} catch (RuntimeException) {
    $updateFailureThrown = true;
}
$assert($updateFailureThrown, 'A successor SQL error was reported as an ordinary CAS miss.');

if ($failed === 0) {
    fwrite(STDOUT, "Requirement-state repository scenarios: passed\n");
}
exit($failed === 0 ? 0 : 1);
