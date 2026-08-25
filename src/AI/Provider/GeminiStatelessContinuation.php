<?php
declare(strict_types=1);

namespace Veyra\AI\Provider;

use Veyra\AI\Contract\ProviderContinuation;
use Veyra\AI\Contract\ProviderFunctionResult;

/**
 * Exact client-managed history for Gemini Interactions API store=false calls.
 */
final class GeminiStatelessContinuation implements ProviderContinuation
{
    /** @param array<int, array<string, mixed>> $history */
    private function __construct(private readonly array $history)
    {
        if ($history === []) {
            throw new \InvalidArgumentException('Gemini stateless history cannot be empty.');
        }
        foreach ($history as $step) {
            if (!is_array($step) || !is_string($step['type'] ?? null)) {
                throw new \InvalidArgumentException('Invalid Gemini stateless history step.');
            }
        }
    }

    /** @param array<int, array<string, mixed>> $input */
    public static function start(array $input): self
    {
        if ($input === []) {
            throw new \InvalidArgumentException('Gemini initial input cannot be empty.');
        }

        return new self([[
            'type' => 'user_input',
            'content' => $input,
        ]]);
    }

    public function providerKey(): string
    {
        return 'google_gemini';
    }

    /** @param array<int, array<string, mixed>> $steps */
    public function appendModelSteps(array $steps): self
    {
        if ($steps === []) {
            throw new \InvalidArgumentException('Gemini model steps cannot be empty.');
        }

        return new self(array_merge($this->history, $steps));
    }

    /** @param array<int, ProviderFunctionResult> $results */
    public function appendFunctionResults(array $results): self
    {
        if ($results === []) {
            throw new \InvalidArgumentException('Gemini function results cannot be empty.');
        }
        $steps = [];
        foreach ($results as $result) {
            if (!$result instanceof ProviderFunctionResult) {
                throw new \InvalidArgumentException('Invalid Gemini function result.');
            }
            $encoded = json_encode(
                $result->result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $steps[] = [
                'type' => 'function_result',
                'name' => str_replace('.', '__', $result->toolName),
                'call_id' => $result->callId,
                'result' => [['type' => 'text', 'text' => $encoded]],
            ];
        }

        return new self(array_merge($this->history, $steps));
    }

    /** @return array<int, array<string, mixed>> */
    public function history(): array
    {
        return $this->history;
    }
}
