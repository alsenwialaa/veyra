<?php
declare(strict_types=1);

namespace Veyra\Experience\Contract;

// Internal contract exceptions are never rendered; adapters escape at the output boundary.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable, versioned customer-visible message payload.
 *
 * This object validates the presentation boundary only. Product, order, cart,
 * confirmation, and ownership authority must already have been resolved by the
 * application layer. Historical snapshots are copied and never refreshed here.
 */
final class RenderingPayload implements JsonSerializable
{
    public const SCHEMA_VERSION = 'veyra.message.v1';

    private const SENDERS = ['shopper', 'ai', 'human', 'system'];
    private const DIRECTIONS = ['auto', 'ltr', 'rtl'];
    private const STATES = [
        'sending',
        'processing',
        'delivered',
        'failed',
        'retrying',
        'cancelled',
    ];
    private const COMPONENT_TYPES = [
        'product',
        'comparison',
        'cart',
        'checkout',
        'order',
        'crm_case',
        'payment_review',
        'return',
        'branch',
        'hours',
        'delivery',
        'handoff',
        'notice',
    ];

    /** @var array<string, mixed> */
    private array $payload;

    /** @param array<string, mixed> $payload */
    private function __construct(array $payload)
    {
        $this->payload = self::copyScalars($payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $payload): self
    {
        self::requireExactKeys(
            $payload,
            [
                'schema_version',
                'message_id',
                'conversation_id',
                'sender',
                'content',
                'created_at',
                'display_timezone',
                'state',
                'rendering_version',
            ],
            [
                'reply_quote',
                'product_references',
                'components',
                'current_state',
                'correlation_id',
            ],
            '$'
        );

        self::expectLiteral($payload['schema_version'], self::SCHEMA_VERSION, '$.schema_version');
        self::expectIdentifier($payload['message_id'], '$.message_id');
        self::expectIdentifier($payload['conversation_id'], '$.conversation_id');
        self::expectEnum($payload['sender'], self::SENDERS, '$.sender');
        self::expectEnum($payload['state'], self::STATES, '$.state');
        self::expectIdentifier($payload['rendering_version'], '$.rendering_version');
        self::expectDate($payload['created_at'], '$.created_at');
        self::expectString($payload['display_timezone'], '$.display_timezone', 1, 96);

        if (!is_array($payload['content'])) {
            throw new InvalidArgumentException('$.content must be an object.');
        }

        self::requireExactKeys(
            $payload['content'],
            ['text', 'language', 'direction'],
            ['modality_label'],
            '$.content'
        );
        self::expectString($payload['content']['text'], '$.content.text', 0, 20000);
        self::expectString($payload['content']['language'], '$.content.language', 2, 35);
        self::expectEnum($payload['content']['direction'], self::DIRECTIONS, '$.content.direction');
        if (array_key_exists('modality_label', $payload['content'])) {
            self::expectString($payload['content']['modality_label'], '$.content.modality_label', 1, 120);
        }

        if (array_key_exists('reply_quote', $payload) && $payload['reply_quote'] !== null) {
            self::validateReplyQuote($payload['reply_quote']);
        }

        $references = $payload['product_references'] ?? [];
        if (!is_array($references) || !array_is_list($references) || count($references) > 3) {
            throw new InvalidArgumentException('$.product_references must contain zero to three references.');
        }
        foreach ($references as $index => $reference) {
            self::validateProductReference($reference, '$.product_references[' . $index . ']');
        }

        $components = $payload['components'] ?? [];
        if (!is_array($components) || !array_is_list($components) || count($components) > 24) {
            throw new InvalidArgumentException('$.components must be a bounded list.');
        }
        foreach ($components as $index => $component) {
            self::validateComponent($component, '$.components[' . $index . ']');
        }

        if (array_key_exists('current_state', $payload) && $payload['current_state'] !== null) {
            if (!is_array($payload['current_state'])) {
                throw new InvalidArgumentException('$.current_state must be an object or null.');
            }
            self::ensureScalarTree($payload['current_state'], '$.current_state');
        }

        if (array_key_exists('correlation_id', $payload) && $payload['correlation_id'] !== null) {
            self::expectIdentifier($payload['correlation_id'], '$.correlation_id');
        }

        return new self($payload);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return self::copyScalars($this->payload);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->jsonSerialize();
    }

    public function messageId(): string
    {
        return (string) $this->payload['message_id'];
    }

    public function renderingVersion(): string
    {
        return (string) $this->payload['rendering_version'];
    }

    /** @param mixed $quote */
    private static function validateReplyQuote($quote): void
    {
        if (!is_array($quote)) {
            throw new InvalidArgumentException('$.reply_quote must be an object.');
        }

        self::requireExactKeys(
            $quote,
            [
                'schema_version',
                'source_message_id',
                'source_sender',
                'excerpt',
                'source_time',
                'source_available',
                'redacted',
            ],
            ['modality_label'],
            '$.reply_quote'
        );
        self::expectLiteral($quote['schema_version'], 'veyra.reply_quote.v1', '$.reply_quote.schema_version');
        self::expectIdentifier($quote['source_message_id'], '$.reply_quote.source_message_id');
        self::expectEnum($quote['source_sender'], self::SENDERS, '$.reply_quote.source_sender');
        self::expectString($quote['excerpt'], '$.reply_quote.excerpt', 0, 500);
        self::expectDate($quote['source_time'], '$.reply_quote.source_time');
        self::expectBoolean($quote['source_available'], '$.reply_quote.source_available');
        self::expectBoolean($quote['redacted'], '$.reply_quote.redacted');
        if (array_key_exists('modality_label', $quote)) {
            self::expectString($quote['modality_label'], '$.reply_quote.modality_label', 1, 120);
        }
    }

    /** @param mixed $reference */
    private static function validateProductReference($reference, string $path): void
    {
        if (!is_array($reference)) {
            throw new InvalidArgumentException($path . ' must be an object.');
        }

        self::requireExactKeys(
            $reference,
            [
                'schema_version',
                'reference_id',
                'source_message_id',
                'snapshot',
                'context_only',
                'commerce_authorization',
            ],
            ['current_state'],
            $path
        );
        self::expectLiteral($reference['schema_version'], 'veyra.product_reference.v1', $path . '.schema_version');
        self::expectIdentifier($reference['reference_id'], $path . '.reference_id');
        self::expectIdentifier($reference['source_message_id'], $path . '.source_message_id');
        self::expectBoolean($reference['context_only'], $path . '.context_only');
        self::expectBoolean($reference['commerce_authorization'], $path . '.commerce_authorization');
        if ($reference['context_only'] !== true || $reference['commerce_authorization'] !== false) {
            throw new InvalidArgumentException($path . ' must remain context-only and cannot confer commerce authority.');
        }
        if (!is_array($reference['snapshot'])
            || array_is_list($reference['snapshot'])
            || !is_int($reference['snapshot']['product_id'] ?? null)
            || $reference['snapshot']['product_id'] < 1
            || !is_int($reference['snapshot']['variation_id'] ?? null)
            || $reference['snapshot']['variation_id'] < 0
        ) {
            throw new InvalidArgumentException($path . '.snapshot must identify one exact product and variation.');
        }
        self::ensureScalarTree($reference['snapshot'], $path . '.snapshot');
        if (array_key_exists('current_state', $reference) && $reference['current_state'] !== null) {
            if (!is_array($reference['current_state'])) {
                throw new InvalidArgumentException($path . '.current_state must be an object or null.');
            }
            self::ensureScalarTree($reference['current_state'], $path . '.current_state');
        }
    }

    /** @param mixed $component */
    private static function validateComponent($component, string $path): void
    {
        if (!is_array($component)) {
            throw new InvalidArgumentException($path . ' must be an object.');
        }

        self::requireExactKeys(
            $component,
            ['schema_version', 'component_id', 'type', 'snapshot'],
            ['current_state', 'actions'],
            $path
        );
        self::expectLiteral($component['schema_version'], 'veyra.component.v1', $path . '.schema_version');
        self::expectIdentifier($component['component_id'], $path . '.component_id');
        self::expectEnum($component['type'], self::COMPONENT_TYPES, $path . '.type');

        if (!is_array($component['snapshot'])) {
            throw new InvalidArgumentException($path . '.snapshot must be an object.');
        }
        self::ensureScalarTree($component['snapshot'], $path . '.snapshot');

        if (array_key_exists('current_state', $component) && $component['current_state'] !== null) {
            if (!is_array($component['current_state'])) {
                throw new InvalidArgumentException($path . '.current_state must be an object.');
            }
            self::ensureScalarTree($component['current_state'], $path . '.current_state');
        }

        if (array_key_exists('actions', $component) && $component['actions'] !== null) {
            if (!is_array($component['actions']) || !array_is_list($component['actions']) || count($component['actions']) > 12) {
                throw new InvalidArgumentException($path . '.actions must be a bounded list.');
            }
            foreach ($component['actions'] as $index => $action) {
                self::validateAction($action, $path . '.actions[' . $index . ']');
            }
        }
    }

    /** @param mixed $action */
    private static function validateAction($action, string $path): void
    {
        if (!is_array($action)) {
            throw new InvalidArgumentException($path . ' must be an object.');
        }
        self::requireExactKeys(
            $action,
            ['action_id', 'label', 'kind'],
            [
                'secondary',
                'disabled',
                'intent_text',
                'url',
                'product_reference_id',
                'product_label',
                'idempotency_key',
                'requires_confirmation',
                'confirmation_id',
                'summary_message_id',
                'state_hash',
                'confirmation_summary',
                'summary_complete',
                'expires_at',
            ],
            $path
        );
        self::expectIdentifier($action['action_id'], $path . '.action_id');
        self::expectString($action['label'], $path . '.label', 1, 120);
        self::expectEnum($action['kind'], ['compose', 'navigate', 'interaction'], $path . '.kind');

        foreach (['secondary', 'disabled', 'requires_confirmation', 'summary_complete'] as $key) {
            if (array_key_exists($key, $action)) {
                self::expectBoolean($action[$key], $path . '.' . $key);
            }
        }

        if ($action['kind'] === 'compose') {
            self::expectString($action['intent_text'] ?? null, $path . '.intent_text', 0, 4000);
        }
        if ($action['kind'] === 'navigate') {
            self::expectString($action['url'] ?? null, $path . '.url', 1, 2048);
        }
        if ($action['kind'] === 'interaction') {
            self::expectIdentifier($action['idempotency_key'] ?? null, $path . '.idempotency_key');
            if (($action['requires_confirmation'] ?? false) === true) {
                self::expectIdentifier($action['confirmation_id'] ?? null, $path . '.confirmation_id');
                self::expectIdentifier($action['summary_message_id'] ?? null, $path . '.summary_message_id');
                self::expectIdentifier($action['state_hash'] ?? null, $path . '.state_hash');
                self::expectString($action['confirmation_summary'] ?? null, $path . '.confirmation_summary', 1, 4000);
                if (($action['summary_complete'] ?? false) !== true) {
                    throw new InvalidArgumentException($path . '.summary_complete must be true for confirmation.');
                }
                self::expectDate($action['expires_at'] ?? null, $path . '.expires_at');
            }
        }

        foreach (['product_reference_id', 'confirmation_id', 'summary_message_id', 'state_hash'] as $key) {
            if (array_key_exists($key, $action) && $action[$key] !== null) {
                self::expectIdentifier($action[$key], $path . '.' . $key);
            }
        }
        foreach (['product_label', 'intent_text', 'url', 'confirmation_summary', 'expires_at'] as $key) {
            if (array_key_exists($key, $action) && $action[$key] !== null && !is_string($action[$key])) {
                throw new InvalidArgumentException($path . '.' . $key . ' must be a string.');
            }
        }
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $required
     * @param list<string> $optional
     */
    private static function requireExactKeys(array $value, array $required, array $optional, string $path): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                throw new InvalidArgumentException($path . '.' . $key . ' is required.');
            }
        }

        $allowed = array_fill_keys(array_merge($required, $optional), true);
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidArgumentException($path . ' contains an unexpected field.');
            }
        }
    }

    /** @param mixed $value */
    private static function expectLiteral($value, string $literal, string $path): void
    {
        if ($value !== $literal) {
            throw new InvalidArgumentException($path . ' must be ' . $literal . '.');
        }
    }

    /** @param mixed $value */
    private static function expectIdentifier($value, string $path): void
    {
        self::expectString($value, $path, 1, 128);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', (string) $value)) {
            throw new InvalidArgumentException($path . ' is not a valid opaque identifier.');
        }
    }

    /** @param mixed $value @param list<string> $allowed */
    private static function expectEnum($value, array $allowed, string $path): void
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($path . ' contains an unsupported value.');
        }
    }

    /** @param mixed $value */
    private static function expectString($value, string $path, int $minimum, int $maximum): void
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($path . ' must be a string.');
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length < $minimum || $length > $maximum) {
            throw new InvalidArgumentException($path . ' has an invalid length.');
        }
    }

    /** @param mixed $value */
    private static function expectBoolean($value, string $path): void
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException($path . ' must be a boolean.');
        }
    }

    /** @param mixed $value */
    private static function expectDate($value, string $path): void
    {
        self::expectString($value, $path, 10, 64);
        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',
                (string) $value
            ) !== 1
            || strtotime((string) $value) === false
        ) {
            throw new InvalidArgumentException($path . ' must be an absolute timestamp.');
        }
    }

    /** @param mixed $value */
    private static function ensureScalarTree($value, string $path, int $depth = 0): void
    {
        if ($depth > 12) {
            throw new InvalidArgumentException($path . ' exceeds the maximum nesting depth.');
        }
        if (is_null($value) || is_scalar($value)) {
            return;
        }
        if (!is_array($value) || count($value) > 250) {
            throw new InvalidArgumentException($path . ' must be a bounded scalar tree.');
        }
        foreach ($value as $key => $item) {
            if (!is_int($key) && !is_string($key)) {
                throw new InvalidArgumentException($path . ' contains an invalid key.');
            }
            self::ensureScalarTree($item, $path . '[' . (string) $key . ']', $depth + 1);
        }
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private static function copyScalars(array $value): array
    {
        self::ensureScalarTree($value, '$');

        /** @var array<string, mixed> $copy */
        $copy = json_decode((string) json_encode($value, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        return $copy;
    }
}
