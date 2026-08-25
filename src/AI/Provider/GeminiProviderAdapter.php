<?php
declare(strict_types=1);

namespace Veyra\AI\Provider;

use Veyra\AI\Contract\ProviderAdapter;
use Veyra\AI\Contract\ProviderRequest;
use Veyra\AI\Contract\ProviderResult;

final class GeminiProviderAdapter implements ProviderAdapter
{
    public function __construct(
        private readonly RouteManifest $manifest,
        private readonly CredentialVault $vault,
        private readonly ProviderPayloadValidator $validator,
        private readonly ProviderTransmissionGate $transmissionGate
    ) {
    }

    public function providerKey(): string
    {
        return 'google_gemini';
    }

    public function execute(ProviderRequest $request): ProviderResult
    {
        try {
            $route = $this->manifest->route($request->routeId);
        } catch (\Throwable) {
            return ProviderResult::failure('provider_manifest_unavailable', false, $this->manifest->version());
        }
        if (($route['provider'] ?? '') !== $this->providerKey()) {
            return ProviderResult::failure('provider_route_mismatch', false, $this->manifest->version());
        }
        $transmission = $this->transmissionGate->decision($request);
        if (!$transmission['allowed']) {
            return ProviderResult::failure($transmission['reason_code'], false, $this->manifest->version());
        }
        if ($request->continuation !== null && !$request->continuation instanceof GeminiStatelessContinuation) {
            return ProviderResult::failure('provider_continuation_mismatch', false, $this->manifest->version());
        }
        try {
            $exchange = $request->continuation instanceof GeminiStatelessContinuation
                ? $request->continuation->appendFunctionResults($request->functionResults)
                : GeminiStatelessContinuation::start($request->input);
        } catch (\Throwable) {
            return ProviderResult::failure('provider_continuation_invalid', false, $this->manifest->version());
        }

        $body = [
            'model' => (string) $route['model_id'],
            'system_instruction' => $request->systemInstruction,
            'input' => $exchange->history(),
            'store' => (bool) ($route['store_requests'] ?? false),
            'response_format' => [
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => $request->responseSchema,
            ],
        ];
        if ($request->tools !== []) {
            $body['tools'] = $this->mapTools($request->tools);
        }
        $outbound = $this->transmissionGate->outboundDecision($request, $body);
        if (!$outbound['allowed']) {
            return ProviderResult::failure($outbound['reason_code'], false, $this->manifest->version());
        }
        // Credential access occurs only after both the provider-independent
        // request and the finalized Gemini body have passed the boundary.
        $apiKey = $this->vault->geminiCredential();
        if ($apiKey === null) {
            return ProviderResult::failure('provider_unconfigured', false, $this->manifest->version());
        }
        if (!function_exists('wp_remote_post')) {
            unset($apiKey);
            return ProviderResult::failure('provider_http_unavailable', false, $this->manifest->version());
        }

        $started = microtime(true);
        $response = wp_remote_post((string) $route['endpoint'], [
            'timeout' => min($request->timeoutSeconds, (int) ($route['timeout_seconds'] ?? 25)),
            'redirection' => 0,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $apiKey,
            ],
            'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'data_format' => 'body',
            'reject_unsafe_urls' => true,
        ]);
        $latency = (int) round((microtime(true) - $started) * 1000);
        unset($apiKey);

        if (is_wp_error($response)) {
            return ProviderResult::failure('provider_transport_error', true, $this->manifest->version());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            $retrySafe = in_array($status, [408, 429, 500, 502, 503, 504], true);
            return ProviderResult::failure($this->mapHttpError($status), $retrySafe, $this->manifest->version());
        }
        $responseBody = (string) wp_remote_retrieve_body($response);
        $maximumResponseBytes = (int) ($route['max_response_bytes'] ?? 524288);
        if ($maximumResponseBytes < 4096 || $maximumResponseBytes > 2097152
            || strlen($responseBody) > $maximumResponseBytes
        ) {
            return ProviderResult::failure('provider_response_limit_exceeded', false, $this->manifest->version());
        }
        $decoded = json_decode($responseBody, true, 64);
        if (!is_array($decoded)) {
            return ProviderResult::failure('provider_protocol_error', false, $this->manifest->version());
        }
        $interaction = new GeminiInteractionResponse($decoded);
        if (!$interaction->valid()) {
            return ProviderResult::failure('provider_protocol_error', false, $this->manifest->version());
        }
        $modelSteps = $interaction->modelSteps();
        $nativeCalls = $interaction->nativeToolCalls();
        if ($nativeCalls !== [] && $interaction->status() !== 'requires_action') {
            return ProviderResult::failure('provider_protocol_error', false, $this->manifest->version());
        }
        if ($nativeCalls === [] && $interaction->status() !== 'completed') {
            return ProviderResult::failure($this->mapInteractionStatus($interaction->status()), false, $this->manifest->version());
        }
        if ($request->phase !== ProviderRequest::PHASE_READINESS && $nativeCalls !== []) {
            // Shopper phases declare no native Gemini tools. Tool execution is
            // driven only by the validated Veyra decision contract and server
            // registry, never by an undeclared provider-side function call.
            return ProviderResult::failure('provider_unexpected_tool_call', false, $this->manifest->version());
        }
        $jsonText = $interaction->outputText();
        if ($request->phase === ProviderRequest::PHASE_READINESS) {
            $readinessPayload = is_string($jsonText)
                ? $this->validator->validateReadinessPayload(json_decode($jsonText, true, 16))
                : null;
            if ($readinessPayload === null || count($nativeCalls) !== 1) {
                return ProviderResult::failure('provider_contract_error', false, $this->manifest->version());
            }
            $readinessPayload['tool_calls'] = $nativeCalls;
            return ProviderResult::success($readinessPayload, [
                'latency_ms' => $latency,
                'input_tokens' => $interaction->usage('input_tokens'),
                'output_tokens' => $interaction->usage('output_tokens'),
            ], $this->manifest->version());
        }
        $payload = is_string($jsonText) ? json_decode($jsonText, true, 64) : null;
        if (!is_array($payload)) {
            if ($nativeCalls !== []) {
                $payload = [
                    'schema_version' => '1.0.0',
                    'turn_type' => 'plan',
                    'language' => 'und',
                    'direction' => 'ltr',
                    'reply' => ['text' => '', 'components' => []],
                    'tool_calls' => $nativeCalls,
                    'proposed_updates' => [],
                    'evidence_requirements' => [],
                    'claims' => [],
                ];
            }
        }
        $payload = match ($request->metadata['contract'] ?? null) {
            'agent_decision_v1' => $this->validator->validateDecisionPayload($payload),
            'agent_response_v1' => $this->validator->validateResponseContractPayload($payload),
            'semantic_response_verification_v1' => $this->validator->validateSemanticVerificationPayload($payload),
            default => $this->validator->validateTurnPayload($payload),
        };
        if ($payload === null) {
            return ProviderResult::failure('provider_contract_error', false, $this->manifest->version());
        }
        $continuation = null;
        if ($nativeCalls !== []) {
            try {
                $continuation = $exchange->appendModelSteps($modelSteps);
            } catch (\Throwable) {
                return ProviderResult::failure('provider_continuation_invalid', false, $this->manifest->version());
            }
        }
        return ProviderResult::success($payload, [
            'latency_ms' => $latency,
            'input_tokens' => $interaction->usage('input_tokens'),
            'output_tokens' => $interaction->usage('output_tokens'),
        ], $this->manifest->version(), $continuation);
    }

    /** @param array<int, array<string, mixed>> $tools @return array<int, array<string, mixed>> */
    private function mapTools(array $tools): array
    {
        return array_map(static function (array $tool): array {
            return [
                'type' => 'function',
                'name' => str_replace('.', '__', (string) ($tool['name'] ?? '')),
                'description' => (string) ($tool['description'] ?? ''),
                'parameters' => is_array($tool['input_schema'] ?? null) ? $tool['input_schema'] : ['type' => 'object'],
            ];
        }, $tools);
    }

    private function mapHttpError(int $status): string
    {
        return match ($status) {
            400 => 'provider_bad_request',
            401, 403 => 'provider_authentication_failed',
            404 => 'provider_model_unavailable',
            408, 504 => 'provider_timeout',
            429 => 'provider_rate_limited',
            default => $status >= 500 ? 'provider_unavailable' : 'provider_request_rejected',
        };
    }

    private function mapInteractionStatus(string $status): string
    {
        return match ($status) {
            'failed' => 'provider_interaction_failed',
            'cancelled' => 'provider_interaction_cancelled',
            'incomplete' => 'provider_interaction_incomplete',
            'budget_exceeded' => 'provider_interaction_budget_exceeded',
            'in_progress', 'queued' => 'provider_interaction_not_terminal',
            default => 'provider_protocol_error',
        };
    }
}
