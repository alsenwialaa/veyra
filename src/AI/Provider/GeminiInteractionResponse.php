<?php
declare(strict_types=1);

namespace Veyra\AI\Provider;

use Veyra\Shared\Domain\CanonicalJson;

/**
 * Strict decoder for non-streaming Gemini Interactions raw REST responses.
 *
 * The legacy `outputs` representation was removed on 2026-06-08. SDK-only
 * convenience properties are deliberately not accepted by this raw REST path:
 * every model result must be represented by a well-formed `steps` entry.
 */
final class GeminiInteractionResponse
{
    private const MAX_STEPS = 256;
    private const MAX_TEXT_BYTES = 524288;
    private const MAX_ARGUMENT_BYTES = 131072;

    private ?bool $valid = null;
    /** @var list<array<string,mixed>>|null */
    private ?array $validatedSteps = null;

    /** @param array<string, mixed> $response */
    public function __construct(private readonly array $response)
    {
    }

    public function valid(): bool
    {
        if ($this->valid !== null) {
            return $this->valid;
        }
        $this->valid = false;
        $this->validatedSteps = [];

        // These are legacy REST or SDK-derived compatibility aliases, not the
        // current raw REST representation used by this adapter.
        foreach (['output', 'outputs', 'output_text', 'output_audio', 'output_image', 'output_video'] as $legacy) {
            if (array_key_exists($legacy, $this->response)) {
                return false;
            }
        }

        $status = $this->status();
        if (!in_array($status, [
            'in_progress', 'queued', 'requires_action', 'completed',
            'failed', 'cancelled', 'incomplete', 'budget_exceeded',
        ], true)) {
            return false;
        }

        $rawSteps = $this->response['steps'] ?? null;
        if ($rawSteps === null) {
            // Error/non-terminal interactions may carry no model-generated
            // steps. Successful and action-required responses may not.
            if (in_array($status, ['completed', 'requires_action'], true)) {
                return false;
            }
            $this->valid = true;
            return true;
        }
        if (!is_array($rawSteps) || !array_is_list($rawSteps)
            || count($rawSteps) > self::MAX_STEPS
            || (in_array($status, ['completed', 'requires_action'], true) && $rawSteps === [])
        ) {
            return false;
        }

        $seenCalls = [];
        $steps = [];
        foreach ($rawSteps as $step) {
            if (!is_array($step) || array_is_list($step) || !is_string($step['type'] ?? null)) {
                return false;
            }
            $type = $step['type'];
            if ($type === 'model_output') {
                if (!$this->validModelOutput($step)) {
                    return false;
                }
            } elseif ($type === 'function_call') {
                if (!$this->validFunctionCall($step) || isset($seenCalls[$step['id']])) {
                    return false;
                }
                $seenCalls[$step['id']] = true;
            } elseif ($type === 'thought') {
                if (!$this->validThought($step)) {
                    return false;
                }
            } else {
                // This route declares custom functions only. Server-tool,
                // function-result, user-input, and unknown response steps are
                // protocol-invalid rather than silently discarded.
                return false;
            }
            $steps[] = $step;
        }

        try {
            if (strlen(CanonicalJson::encode($steps)) > self::MAX_TEXT_BYTES * 2) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        $this->validatedSteps = $steps;
        $this->valid = true;
        return true;
    }

    public function status(): string
    {
        return is_string($this->response['status'] ?? null) ? $this->response['status'] : '';
    }

    /** @return list<array<string, mixed>> */
    public function modelSteps(): array
    {
        return $this->valid() ? ($this->validatedSteps ?? []) : [];
    }

    public function outputText(): ?string
    {
        if (!$this->valid()) {
            return null;
        }
        $parts = [];
        $bytes = 0;
        foreach ($this->validatedSteps ?? [] as $step) {
            if ($step['type'] !== 'model_output') {
                continue;
            }
            foreach ($step['content'] as $content) {
                $text = $content['text'];
                $bytes += strlen($text);
                if ($bytes > self::MAX_TEXT_BYTES) {
                    return null;
                }
                $parts[] = $text;
            }
        }

        return $parts === [] ? null : implode('', $parts);
    }

    /** @return list<array<string, mixed>> */
    public function nativeToolCalls(): array
    {
        if (!$this->valid()) {
            return [];
        }
        $calls = [];
        foreach ($this->validatedSteps ?? [] as $step) {
            if ($step['type'] !== 'function_call') {
                continue;
            }
            $calls[] = [
                'call_id' => $step['id'],
                'name' => str_replace('__', '.', $step['name']),
                'version' => '1.0.0',
                'arguments' => $step['arguments'],
            ];
        }
        return $calls;
    }

    public function usage(string $key): ?int
    {
        if (!$this->valid()) {
            return null;
        }
        $usage = $this->response['usage'] ?? null;
        if (!is_array($usage) || array_is_list($usage)) {
            return null;
        }
        $field = match ($key) {
            'input_tokens' => 'total_input_tokens',
            'output_tokens' => 'total_output_tokens',
            default => null,
        };
        $value = $field === null ? null : ($usage[$field] ?? null);
        return is_int($value) && $value >= 0 ? $value : null;
    }

    /** @param array<string,mixed> $step */
    private function validModelOutput(array $step): bool
    {
        if (!$this->exactKeys($step, ['type', 'content'])
            || !is_array($step['content']) || !array_is_list($step['content'])
            || $step['content'] === [] || count($step['content']) > 1024
        ) {
            return false;
        }
        $bytes = 0;
        foreach ($step['content'] as $content) {
            if (!is_array($content) || array_is_list($content)
                || !$this->exactKeys($content, ['type', 'text'])
                || ($content['type'] ?? null) !== 'text'
                || !is_string($content['text'] ?? null)
                || preg_match('//u', $content['text']) !== 1
            ) {
                return false;
            }
            $bytes += strlen($content['text']);
            if ($bytes > self::MAX_TEXT_BYTES) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $step */
    private function validFunctionCall(array $step): bool
    {
        if (!$this->exactKeys($step, ['type', 'id', 'name', 'arguments'])
            || !$this->opaque($step['id'] ?? null, 191)
            || !is_string($step['name'] ?? null)
            || strlen($step['name']) > 160
            || preg_match('/^[a-z][a-z0-9_]{0,63}__[a-z][a-z0-9_]{0,95}$/D', $step['name']) !== 1
            || !is_array($step['arguments'] ?? null)
            || (($step['arguments'] ?? []) !== [] && array_is_list($step['arguments']))
        ) {
            return false;
        }
        try {
            return strlen(CanonicalJson::encode($step['arguments'])) <= self::MAX_ARGUMENT_BYTES;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $step */
    private function validThought(array $step): bool
    {
        $keys = array_keys($step);
        sort($keys, SORT_STRING);
        if (!in_array($keys, [
            ['signature', 'type'],
            ['summary', 'type'],
            ['signature', 'summary', 'type'],
        ], true)) {
            return false;
        }
        if (array_key_exists('signature', $step)
            && (!is_string($step['signature']) || $step['signature'] === ''
                || strlen($step['signature']) > 65536 || preg_match('//u', $step['signature']) !== 1)
        ) {
            return false;
        }
        if (!array_key_exists('summary', $step)) {
            return true;
        }
        if (!is_array($step['summary']) || !array_is_list($step['summary'])
            || $step['summary'] === [] || count($step['summary']) > 64
        ) {
            return false;
        }
        $bytes = 0;
        foreach ($step['summary'] as $content) {
            if (!is_array($content) || array_is_list($content)
                || !$this->exactKeys($content, ['type', 'text'])
                || ($content['type'] ?? null) !== 'text'
                || !is_string($content['text'] ?? null)
                || preg_match('//u', $content['text']) !== 1
            ) {
                return false;
            }
            $bytes += strlen($content['text']);
            if ($bytes > 65536) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private function exactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }

    private function opaque(mixed $value, int $maximum): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maximum
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) === 1;
    }
}
