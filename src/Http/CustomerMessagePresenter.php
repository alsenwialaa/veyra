<?php

declare(strict_types=1);

namespace Veyra\Http;

use Veyra\Experience\Contract\MessagePayloadAssembler;
use Veyra\Experience\Contract\ProductReferenceIdentity;

/** Maps actor-owned immutable message rows to the strict customer renderer. */
final class CustomerMessagePresenter
{
    public function __construct(private readonly MessagePayloadAssembler $assembler)
    {
    }

    /** @param array<string, mixed> $message @return array<string, mixed> */
    public function present(string $conversationId, array $message): array
    {
        $content = is_array($message['content'] ?? null) ? $message['content'] : [];
        $render = is_array($message['render'] ?? null) ? $message['render'] : [];
        $sender = match ((string) ($message['sender_type'] ?? 'system')) {
            'customer', 'shopper' => 'shopper',
            'ai' => 'ai',
            'human', 'support', 'staff' => 'human',
            default => 'system',
        };
        $language = $this->language((string) ($message['language'] ?? ($render['language'] ?? 'en')));
        $direction = in_array($message['direction'] ?? ($render['direction'] ?? null), ['ltr', 'rtl'], true)
            ? (string) ($message['direction'] ?? $render['direction'])
            : 'auto';
        $replyQuote = $this->replyQuote($render['reply_snapshot'] ?? null);
        $references = $this->productReferences(
            is_array($message['product_references'] ?? null) ? $message['product_references'] : [],
            (string) ($message['message_id'] ?? '')
        );
        $createdAt = $this->timestamp((string) ($message['created_at'] ?? ''));
        $timezone = function_exists('wp_timezone') ? wp_timezone()->getName() : 'UTC';
        $correlationId = is_string($message['correlation_id'] ?? null)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $message['correlation_id']) === 1
            ? $message['correlation_id']
            : null;
        $payload = $this->assembler->assemble(
            (string) $message['message_id'],
            $conversationId,
            $sender,
            is_string($content['text'] ?? null) ? $content['text'] : '',
            $language,
            $direction,
            $createdAt,
            $timezone,
            (string) ($message['rendering_schema_version'] ?? '1.0.0'),
            is_array($render['components'] ?? null) ? $render['components'] : [],
            $replyQuote,
            $references,
            null,
            $correlationId
        );
        return $payload->toArray();
    }

    /** @param mixed $raw @return array<string, mixed>|null */
    private function replyQuote(mixed $raw): ?array
    {
        if (!is_array($raw) || !is_string($raw['message_id'] ?? null)) {
            return null;
        }
        $content = is_array($raw['content'] ?? null) ? $raw['content'] : [];
        $text = is_string($content['text'] ?? null) ? $content['text'] : '';
        $excerpt = function_exists('mb_substr') ? mb_substr($text, 0, 500) : substr($text, 0, 500);
        $sender = match ((string) ($raw['sender_type'] ?? 'system')) {
            'customer', 'shopper' => 'shopper',
            'ai' => 'ai',
            'human', 'support', 'staff' => 'human',
            default => 'system',
        };
        return [
            'schema_version' => 'veyra.reply_quote.v1',
            'source_message_id' => $raw['message_id'],
            'source_sender' => $sender,
            'excerpt' => $excerpt,
            'source_time' => $this->timestamp((string) ($raw['created_at'] ?? '')),
            'source_available' => true,
            'redacted' => false,
        ];
    }

    /** @param list<mixed> $raw @return list<array<string, mixed>> */
    private function productReferences(array $raw, string $fallbackSourceMessageId): array
    {
        return ProductReferenceIdentity::presentReferences($raw, $fallbackSourceMessageId);
    }

    private function language(string $value): string
    {
        $value = str_replace('_', '-', trim($value));
        return preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $value) === 1 ? $value : 'en';
    }

    private function timestamp(string $value): string
    {
        $time = strtotime($value);
        return $time === false ? gmdate(DATE_ATOM) : gmdate(DATE_ATOM, $time);
    }
}
