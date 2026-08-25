<?php
declare(strict_types=1);

namespace Veyra\AI\Provider;

use Veyra\AI\Contract\ProviderAdapter;
use Veyra\AI\Contract\ProviderRequest;

final class ProviderReadinessService
{
    public const READINESS_SYSTEM_INSTRUCTION = 'This is an explicit provider capability test. Return only the required schema and request diagnostics.probe exactly once with the supplied nonce. Do not include secrets or other data.';
    public const READINESS_TOOL_DESCRIPTION = 'Echo a readiness nonce. This test tool has no side effects.';

    private readonly ProviderReadinessStateStore $states;
    private readonly ProviderRequestAttestor $requestAttestor;

    public function __construct(
        private readonly ProviderAdapter $provider,
        private readonly ProviderPayloadValidator $validator,
        private readonly RouteManifest $manifest,
        ?ProviderReadinessStateStore $states = null,
        ?ProviderRequestAttestor $requestAttestor = null
    ) {
        $this->states = $states ?? new ProviderReadinessStateStore();
        $this->requestAttestor = $requestAttestor ?? new ProviderRequestAttestor();
    }

    /** @return array<string, mixed> */
    public function current(): array
    {
        return $this->states->current($this->manifest);
    }

    /**
     * Explicit admin action only. It is intentionally never called by activation,
     * migration, cron, ordinary health-page rendering, or shopper bootstrap.
     *
     * @return array<string, mixed>
     */
    public function runExplicitTest(): array
    {
        $nonce = bin2hex(random_bytes(8));
        $this->persist([ 
            'state' => 'Testing', 'route_id' => 'default_text_tool_orchestration',
            'route_version' => $this->manifest->version(), 'checked_at' => gmdate(DATE_ATOM),
            'capabilities' => [], 'safe_error_code' => null, 'release_certified' => false,
        ]);

        $result = $this->provider->execute($this->requestAttestor->seal(new ProviderRequest(
            'default_text_tool_orchestration',
            self::READINESS_SYSTEM_INSTRUCTION,
            [['type' => 'text', 'text' => 'Capability probe nonce: ' . $nonce]],
            [[
                'name' => 'diagnostics.probe',
                'version' => '1.0.0',
                'description' => self::READINESS_TOOL_DESCRIPTION,
                'input_schema' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['nonce'],
                    'properties' => ['nonce' => ['type' => 'string', 'enum' => [$nonce]]],
                ],
            ]],
            $this->validator->readinessSchema(),
            20,
            ['purpose' => 'explicit_provider_readiness'],
            null,
            [],
            ProviderRequest::TRAFFIC_READINESS,
            ProviderRequest::PURPOSE_READINESS,
            null,
            ProviderRequest::PHASE_READINESS
        )));
        $payload = is_array($result->payload) && !array_is_list($result->payload) ? $result->payload : [];
        $calls = is_array($payload['tool_calls'] ?? null) && array_is_list($payload['tool_calls'])
            ? $payload['tool_calls']
            : [];
        $call = count($calls) === 1 && is_array($calls[0] ?? null) ? $calls[0] : [];
        $callKeys = array_keys($call);
        sort($callKeys, SORT_STRING);
        $toolCapability = $result->status === 'succeeded'
            && ($payload['schema_version'] ?? null) === '1.0.0'
            && ($payload['probe_status'] ?? null) === 'tool_call_requested'
            && $callKeys === ['arguments', 'call_id', 'name', 'version']
            && is_string($call['call_id'] ?? null) && $call['call_id'] !== ''
            && ($call['name'] ?? null) === 'diagnostics.probe'
            && ($call['version'] ?? null) === '1.0.0'
            && is_array($call['arguments'] ?? null)
            && array_keys($call['arguments']) === ['nonce']
            && ($call['arguments']['nonce'] ?? null) === $nonce;
        $ready = $toolCapability;
        $providerSucceeded = $result->status === 'succeeded';
        $state = [
            // This is a bounded capability probe, not release certification.
            // Runtime additionally requires an independently published route,
            // privacy/transmission approval and passing evaluation manifest.
            'state' => $ready ? 'Ready' : 'Blocked',
            'route_id' => 'default_text_tool_orchestration',
            'route_version' => $this->manifest->version(),
            'checked_at' => gmdate(DATE_ATOM),
            'capabilities' => [
                'credentials' => $providerSucceeded,
                'structured_output' => $providerSucceeded,
                'function_calling' => $toolCapability,
                'text' => $providerSucceeded,
            ],
            'safe_error_code' => $ready
                ? null
                : ($providerSucceeded ? 'provider_tool_capability_failed' : $result->code),
            'release_certified' => false,
        ];
        $this->persist($state);
        return $state;
    }

    public function block(string $reasonCode): void
    {
        $this->persist([
            'state' => 'Blocked', 'route_id' => 'default_text_tool_orchestration',
            'route_version' => $this->manifest->version(), 'checked_at' => gmdate(DATE_ATOM),
            'capabilities' => [], 'safe_error_code' => $reasonCode, 'release_certified' => false,
        ]);
    }

    /** @param array<string, mixed> $state */
    private function persist(array $state): void
    {
        $this->states->replace($state);
    }
}
