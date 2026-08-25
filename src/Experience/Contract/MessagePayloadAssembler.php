<?php
declare(strict_types=1);

namespace Veyra\Experience\Contract;

/**
 * Converts verified orchestration presentation intentions into the immutable
 * customer renderer contract. This is deliberately not an authority boundary:
 * non-authoritative commerce components are dropped and no action is invented.
 */
final class MessagePayloadAssembler
{
    /** @var array<string, string> */
    private const TYPE_MAP = [
        'product' => 'product',
        'comparison' => 'comparison',
        'cart' => 'cart',
        'checkout' => 'checkout',
        'order' => 'order',
        'crm' => 'crm_case',
        'crm_case' => 'crm_case',
        'payment_review' => 'payment_review',
        'return' => 'return',
        'branch' => 'branch',
        'hours' => 'hours',
        'delivery' => 'delivery',
        'handoff' => 'handoff',
        'notice' => 'notice',
    ];

    /**
     * @param list<array<string, mixed>> $verifiedComponents
     * @param array<string, mixed>|null  $serverDerivedReplyQuote
     * @param list<array<string, mixed>> $serverDerivedProductReferences
     * @param array<string, mixed>|null  $currentState
     */
    public function assemble(
        string $messageId,
        string $conversationId,
        string $sender,
        string $visibleText,
        string $language,
        string $direction,
        string $createdAt,
        string $displayTimezone,
        string $renderingVersion,
        array $verifiedComponents,
        ?array $serverDerivedReplyQuote = null,
        array $serverDerivedProductReferences = [],
        ?array $currentState = null,
        ?string $correlationId = null
    ): RenderingPayload {
        $payload = [
            'schema_version' => RenderingPayload::SCHEMA_VERSION,
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
            'sender' => $sender,
            'content' => [
                'text' => $visibleText,
                'language' => $language,
                'direction' => $direction,
            ],
            'created_at' => $createdAt,
            'display_timezone' => $displayTimezone,
            'state' => 'delivered',
            'rendering_version' => $renderingVersion,
            'product_references' => $serverDerivedProductReferences,
            'components' => $this->normalizeComponents($verifiedComponents),
        ];

        if ($serverDerivedReplyQuote !== null) {
            $payload['reply_quote'] = $serverDerivedReplyQuote;
        }
        if ($currentState !== null) {
            $payload['current_state'] = $currentState;
        }
        if ($correlationId !== null) {
            $payload['correlation_id'] = $correlationId;
        }

        return RenderingPayload::fromArray($payload);
    }

    /**
     * @param list<array<string, mixed>> $components
     * @return list<array<string, mixed>>
     */
    private function normalizeComponents(array $components): array
    {
        $normalized = [];
        foreach (array_slice($components, 0, 24) as $index => $component) {
            if (!is_array($component)) {
                continue;
            }

            $rawType = is_string($component['type'] ?? null) ? $component['type'] : '';
            $type = self::TYPE_MAP[$rawType] ?? null;
            if ($type === null || $rawType === 'choices') {
                continue;
            }

            $isNotice = $type === 'notice';
            if (!$isNotice && ($component['authoritative'] ?? false) !== true) {
                continue;
            }

            $snapshot = is_array($component['snapshot'] ?? null)
                ? $component['snapshot']
                : (is_array($component['payload'] ?? null) ? $component['payload'] : []);
            if ($isNotice && $snapshot === [] && is_string($component['label'] ?? null)) {
                $snapshot = ['title' => $component['label']];
            }

            if (is_string($component['source_tool'] ?? null)) {
                $snapshot['_source_tool'] = $component['source_tool'];
            }
            if (is_string($component['observed_at'] ?? null)) {
                $snapshot['_observed_at'] = $component['observed_at'];
            }
            $snapshot['_historical'] = true;

            $sourceId = is_string($component['component_id'] ?? null)
                ? $component['component_id']
                : (is_string($component['source_call_id'] ?? null) ? $component['source_call_id'] : 'index-' . $index);
            $componentId = $this->opaqueComponentId($sourceId, $type, $snapshot);

            $normalizedComponent = [
                'schema_version' => 'veyra.component.v1',
                'component_id' => $componentId,
                'type' => $type,
                'snapshot' => $snapshot,
                'actions' => [],
            ];
            if (is_array($component['current_state'] ?? null)) {
                $normalizedComponent['current_state'] = $component['current_state'];
            }
            $normalized[] = $normalizedComponent;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $snapshot */
    private function opaqueComponentId(string $sourceId, string $type, array $snapshot): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,117}$/D', $sourceId) === 1) {
            return 'cmp:' . $sourceId;
        }

        $encoded = json_encode([$sourceId, $type, $snapshot], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return 'cmp:' . substr(hash('sha256', is_string($encoded) ? $encoded : $sourceId), 0, 32);
    }
}
