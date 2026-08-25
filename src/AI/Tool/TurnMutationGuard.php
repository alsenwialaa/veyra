<?php
declare(strict_types=1);

namespace Veyra\AI\Tool;

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;
use Veyra\Shared\Domain\CanonicalJson;

/**
 * Per-turn replay boundary for provider-originated mutations.
 *
 * Provider call IDs and idempotency arguments are untrusted presentation data.
 * Equivalent writes are keyed from the resolved actor, conversation, persisted
 * customer message, logical tool, and canonical material arguments.
 */
final class TurnMutationGuard
{
    /** @var array<string, ToolResult> */
    private array $results = [];

    /**
     * @param array{classification:string,accepts_idempotency_key:bool}|null $profile
     * @return array{call:ToolCall,fingerprint:?string}
     */
    public function prepare(
        ToolCall $call,
        ToolContext $context,
        string $customerMessageId,
        ?array $profile
    ): array {
        if ($profile === null) {
            return ['call' => $call, 'fingerprint' => null];
        }

        $materialArguments = $this->materialArguments($call->arguments);
        $fingerprint = hash('sha256', CanonicalJson::encode([
            'actor_type' => $context->actorType,
            'actor_id' => $context->actorId,
            'conversation_id' => $context->conversationId,
            'customer_message_id' => $customerMessageId,
            'tool' => $call->name,
            'material_arguments' => $materialArguments,
        ]));

        if ($profile['accepts_idempotency_key']) {
            $arguments = $call->arguments;
            unset($arguments['idempotency_key']);
            $arguments['idempotency_key'] = 'veyra-turn-' . $fingerprint;
            $call = new ToolCall($call->callId, $call->name, $call->version, $arguments);
        }

        return ['call' => $call, 'fingerprint' => $fingerprint];
    }

    public function replay(string $fingerprint, ToolCall $call, string $correlationId): ?ToolResult
    {
        $previous = $this->results[$fingerprint] ?? null;
        if (!$previous instanceof ToolResult) {
            return null;
        }

        return new ToolResult(
            $call->callId,
            $call->name,
            $previous->status,
            $previous->code,
            $previous->data,
            $previous->changedResources,
            $previous->authoritative,
            $previous->retrySafe,
            $correlationId,
            $call->version
        );
    }

    public function remember(string $fingerprint, ToolResult $result): void
    {
        $this->results[$fingerprint] = $result;
    }

    /**
     * Remove transport, concurrency and confirmation-envelope fields which can
     * legitimately change between provider rounds without changing the
     * requested business mutation. Resource ids and desired values remain.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function materialArguments(array $arguments): array
    {
        $coordinationFields = [
            'idempotency_key',
            'expected_version',
            'expected_state_hash',
            'state_hash',
            'confirmation_id',
            'confirmation_token',
            'confirmation_version',
            'correlation_id',
        ];

        foreach ($arguments as $key => $value) {
            if (in_array((string) $key, $coordinationFields, true)) {
                unset($arguments[$key]);
                continue;
            }
            if (is_array($value) && !array_is_list($value)) {
                $arguments[$key] = $this->materialArguments($value);
            }
        }

        return $arguments;
    }
}
