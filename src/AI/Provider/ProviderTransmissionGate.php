<?php
declare(strict_types=1);

namespace Veyra\AI\Provider;

use Veyra\AI\Contract\ProviderRequest;
use Veyra\AI\Tool\ToolContext;
use Veyra\Conversation\Contract\ContextBundleContract;
use Veyra\Conversation\Domain\ContextBundle;
use Veyra\Conversation\Domain\ContextBundleAttestor;
use Veyra\Conversation\Domain\ContextBundlePolicy;
use Veyra\Infrastructure\Database\Migration\Migrator;
use Veyra\Shared\Domain\CanonicalJson;

/** Re-evaluates and binds the exact provider body before credentials/network. */
final class ProviderTransmissionGate
{
    public const DECISION_INSTRUCTION = 'Interpret the current turn and return one typed, ordered decision. Do not execute tools or compose the shopper-facing response.';
    public const RESPONSE_INSTRUCTION = 'Compose the truthful shopper-facing response from the validated decision and typed execution outcomes.';

    private readonly ProviderSafeToolResultProjector $toolResultProjector;
    private readonly ProviderProhibitedDataRedactor $prohibitedData;

    public function __construct(
        private readonly RouteManifest $manifest,
        private readonly ProviderReadinessStateStore $states,
        private readonly ContextBundleAttestor $bundleAttestor,
        private readonly ProviderRequestAttestor $requestAttestor,
        private readonly ProviderPayloadValidator $payloadValidator,
        private readonly ContextBundleContract $bundleContract = new ContextBundleContract(),
        ?ProviderSafeToolResultProjector $toolResultProjector = null,
        ?ProviderProhibitedDataRedactor $prohibitedData = null
    ) {
        $this->toolResultProjector = $toolResultProjector ?? new ProviderSafeToolResultProjector();
        $this->prohibitedData = $prohibitedData ?? new ProviderProhibitedDataRedactor();
    }

    /** @return array{allowed:bool,reason_code:string} */
    public function decision(ProviderRequest $request): array
    {
        if (!$this->requestAttestor->verify($request)) {
            return ['allowed' => false, 'reason_code' => 'provider_request_attestation_invalid'];
        }
        if ($request->continuation !== null || $request->functionResults !== []) {
            return ['allowed' => false, 'reason_code' => 'provider_continuation_not_bound'];
        }
        // This candidate publishes exactly one route and one readiness state.
        // Future/fallback routes must gain their own exact certification state
        // before this boundary may transmit them.
        if ($request->routeId !== ProviderReleaseGate::ROUTE_ID) {
            return ['allowed' => false, 'reason_code' => 'provider_route_not_certified'];
        }
        if ($request->trafficClass === ProviderRequest::TRAFFIC_READINESS) {
            if ($request->purpose !== ProviderRequest::PURPOSE_READINESS
                || $request->contextBundle !== null
                || !$this->readinessEnvelopeValid($request)
            ) {
                return ['allowed' => false, 'reason_code' => 'provider_readiness_payload_not_isolated'];
            }
            return ['allowed' => true, 'reason_code' => 'provider_readiness_probe_allowed'];
        }
        if ($request->trafficClass !== ProviderRequest::TRAFFIC_SHOPPER) {
            return ['allowed' => false, 'reason_code' => 'provider_traffic_class_not_transmittable'];
        }

        $bundle = $request->contextBundle;
        if ($bundle === null || !$bundle->transmissionAuthorized()) {
            return ['allowed' => false, 'reason_code' => 'provider_context_transmission_not_authorized'];
        }
        if (!$this->bundleAttestor->verify($bundle)) {
            return ['allowed' => false, 'reason_code' => 'provider_context_bundle_attestation_invalid'];
        }
        if (!$bundle->manifestPersisted()) {
            return ['allowed' => false, 'reason_code' => 'provider_context_manifest_persistence_required'];
        }
        $release = (new ProviderReleaseGate($this->manifest))->decision($this->states->current($this->manifest));
        if (!$release['allowed']) {
            return $release;
        }

        try {
            $route = $this->manifest->route($request->routeId);
            $projection = $bundle->forProvider();
            $reference = $bundle->reference();
            $this->revalidateBundle($bundle, $projection, $route, $request);
        } catch (\Throwable) {
            return ['allowed' => false, 'reason_code' => 'provider_context_bundle_invalid'];
        }
        $expectedPurpose = (string) ($route['shopper_purpose'] ?? ProviderRequest::PURPOSE_SHOPPER);
        if ($request->purpose !== $expectedPurpose
            || ($projection['purpose'] ?? null) !== $expectedPurpose
            || ($projection['schema_version'] ?? null) !== ($route['context_bundle_schema_version'] ?? null)
            || ($projection['privacy']['provider_route_id'] ?? null) !== $request->routeId
            || ($projection['privacy']['route_manifest_version'] ?? null) !== $this->manifest->version()
            || ($projection['privacy']['decision_code'] ?? null) !== 'runtime_ready'
            || ($projection['privacy']['transmission_authorized'] ?? null) !== true
            || ($projection['privacy']['allowed_data_classes'] ?? null) !== ($route['allowed_data_classes'] ?? null)
            || ($reference['provider_route_id'] ?? null) !== $request->routeId
            || ($reference['route_manifest_version'] ?? null) !== $this->manifest->version()
            || ($reference['transmission_decision_code'] ?? null) !== 'runtime_ready'
            || $this->expired((string) ($reference['expires_at'] ?? ''))
        ) {
            return ['allowed' => false, 'reason_code' => 'provider_context_policy_mismatch'];
        }
        if (function_exists('get_option') && defined('VEYRA_SCHEMA_VERSION')
            && (string) get_option(Migrator::SCHEMA_OPTION, '0.0.0') !== (string) VEYRA_SCHEMA_VERSION
        ) {
            return ['allowed' => false, 'reason_code' => 'schema_migration_required'];
        }
        if (!$this->shopperEnvelopeValid($request, $route)) {
            return ['allowed' => false, 'reason_code' => 'provider_shopper_envelope_invalid'];
        }

        $embedded = $this->embeddedContextBundles($request->input);
        if (count($embedded) !== 1) {
            return ['allowed' => false, 'reason_code' => 'provider_context_bundle_binding_missing'];
        }
        try {
            if (!hash_equals(CanonicalJson::encode($projection), CanonicalJson::encode($embedded[0]))) {
                return ['allowed' => false, 'reason_code' => 'provider_context_bundle_binding_mismatch'];
            }
        } catch (\Throwable) {
            return ['allowed' => false, 'reason_code' => 'provider_context_bundle_invalid'];
        }
        foreach ([
            'context_bundle_id' => $bundle->id,
            'context_bundle_version' => $bundle->bundleVersion,
            'context_bundle_hash' => $bundle->hash,
        ] as $key => $expected) {
            if (($request->metadata[$key] ?? null) !== $expected) {
                return ['allowed' => false, 'reason_code' => 'provider_context_metadata_mismatch'];
            }
        }

        try {
            if (!$this->prohibitedData->isAlreadySafe([
                'system_instruction' => $request->systemInstruction,
                'input' => $request->input,
                'tools' => $request->tools,
                'response_schema' => $request->responseSchema,
                'metadata' => $request->metadata,
            ])) {
                return ['allowed' => false, 'reason_code' => 'provider_outbound_prohibited_data_detected'];
            }
        } catch (ProviderDataPolicyException $error) {
            return ['allowed' => false, 'reason_code' => $error->reasonCode];
        } catch (\Throwable) {
            return ['allowed' => false, 'reason_code' => 'provider_outbound_data_policy_failed'];
        }

        return ['allowed' => true, 'reason_code' => 'provider_transmission_allowed'];
    }

    /**
     * @param array<string, mixed> $body Final provider-specific request body.
     * @return array{allowed:bool,reason_code:string}
     */
    public function outboundDecision(ProviderRequest $request, array $body): array
    {
        $preflight = $this->decision($request);
        if (!$preflight['allowed']) {
            return $preflight;
        }
        try {
            $route = $this->manifest->route($request->routeId);
            $expected = [
                'model' => (string) $route['model_id'],
                'system_instruction' => $request->systemInstruction,
                'input' => [['type' => 'user_input', 'content' => $request->input]],
                'store' => (bool) ($route['store_requests'] ?? false),
                'response_format' => [
                    'type' => 'text',
                    'mime_type' => 'application/json',
                    'schema' => $request->responseSchema,
                ],
            ];
            if ($request->tools !== []) {
                $expected['tools'] = $this->mapTools($request->tools);
            }
            $encoded = CanonicalJson::encode($body);
            if (!hash_equals(CanonicalJson::encode($expected), $encoded)) {
                return ['allowed' => false, 'reason_code' => 'provider_outbound_body_mismatch'];
            }
            $maximum = (int) ($route['max_request_bytes'] ?? 524288);
            if ($maximum < 65536 || $maximum > 2097152 || strlen($encoded) > $maximum) {
                return ['allowed' => false, 'reason_code' => 'provider_outbound_body_limit_exceeded'];
            }
            // Last inspection of the exact byte-equivalent body that will be
            // sent. No credential is read and no network call is made if any
            // prohibited value survived an upstream, attested projection.
            if (!$this->prohibitedData->isAlreadySafe($body)) {
                return ['allowed' => false, 'reason_code' => 'provider_outbound_prohibited_data_detected'];
            }
        } catch (ProviderDataPolicyException $error) {
            return ['allowed' => false, 'reason_code' => $error->reasonCode];
        } catch (\Throwable) {
            return ['allowed' => false, 'reason_code' => 'provider_outbound_body_invalid'];
        }
        return ['allowed' => true, 'reason_code' => 'provider_outbound_body_allowed'];
    }

    /** @param array<string, mixed> $projection @param array<string, mixed> $route */
    private function revalidateBundle(ContextBundle $bundle, array $projection, array $route, ProviderRequest $request): void
    {
        $runtime = is_array($projection['selected_data']['runtime_context'] ?? null)
            ? $projection['selected_data']['runtime_context']
            : [];
        $context = new ToolContext(
            $bundle->actorType,
            $bundle->actorId,
            null,
            null,
            $bundle->conversationId,
            [],
            [],
            is_string($runtime['locale'] ?? null) ? $runtime['locale'] : 'und',
            is_string($request->metadata['correlation_id'] ?? null) ? $request->metadata['correlation_id'] : 'provider-boundary'
        );
        $scope = is_array($projection['actor_scope'] ?? null) ? $projection['actor_scope'] : [];
        $policy = new ContextBundlePolicy(
            $request->routeId,
            $this->manifest->version(),
            (string) ($route['shopper_purpose'] ?? ProviderRequest::PURPOSE_SHOPPER),
            true,
            'runtime_ready',
            is_array($route['allowed_data_classes'] ?? null) ? $route['allowed_data_classes'] : [],
            (int) ($route['max_context_bytes'] ?? 65536),
            (int) ($route['max_context_items'] ?? 256),
            (int) ($route['context_bundle_ttl_seconds'] ?? 300)
        );
        $this->bundleContract->assertValid(
            $projection,
            $context,
            $bundle->turnMessageId,
            $policy,
            (string) ($scope['actor_id'] ?? ''),
            (string) ($scope['site_id'] ?? '')
        );
    }

    /** @param array<string, mixed> $route */
    private function shopperEnvelopeValid(ProviderRequest $request, array $route): bool
    {
        if ($request->tools !== [] || $request->continuation !== null || $request->functionResults !== []
            || count($request->input) !== 1 || !$this->exactKeys($request->metadata, [
                'correlation_id', 'conversation_id', 'contract', 'context_bundle_id',
                'context_bundle_version', 'context_bundle_hash',
            ])
            || ($request->metadata['conversation_id'] ?? null) !== $request->contextBundle?->conversationId
            || strlen($request->systemInstruction) > 24000
            || preg_match('//u', $request->systemInstruction) !== 1
        ) {
            return false;
        }
        $item = $request->input[0];
        if (!is_array($item) || !$this->exactKeys($item, ['type', 'text'])
            || ($item['type'] ?? null) !== 'text' || !is_string($item['text'] ?? null)
        ) {
            return false;
        }
        $decoded = json_decode($item['text'], true, 128);
        if (!is_array($decoded) || array_is_list($decoded)) {
            return false;
        }
        $contract = $request->metadata['contract'] ?? null;
        $expected = match ($request->phase) {
            ProviderRequest::PHASE_DECISION => ['contract' => 'agent_decision_v1', 'schema' => $this->payloadValidator->decisionSchema(), 'timeout' => 25, 'keys' => ['instruction', 'context_bundle', 'authorized_tools', 'server_limits']],
            ProviderRequest::PHASE_RESPONSE => ['contract' => 'agent_response_v1', 'schema' => $this->payloadValidator->responseContractSchema(), 'timeout' => 25, 'keys' => ['instruction', 'context_bundle', 'validated_decision', 'binding_outcome', 'step_outcomes', 'typed_tool_results']],
            ProviderRequest::PHASE_SEMANTIC_VERIFICATION => ['contract' => 'semantic_response_verification_v1', 'schema' => $this->payloadValidator->semanticVerificationSchema(), 'timeout' => 20, 'keys' => ['candidate_response', 'typed_tool_results', 'binding_outcome', 'step_outcomes', 'bounded_context_bundle']],
            default => null,
        };
        if (!is_array($expected) || $contract !== $expected['contract']
            || $request->timeoutSeconds !== $expected['timeout']
            || !$this->canonicalEqual($request->responseSchema, $expected['schema'])
            || !$this->exactKeys($decoded, $expected['keys'])
        ) {
            return false;
        }
        if ($request->phase === ProviderRequest::PHASE_DECISION) {
            $limits = is_array($decoded['server_limits'] ?? null) ? $decoded['server_limits'] : [];
            return ($decoded['instruction'] ?? null) === self::DECISION_INSTRUCTION
                && is_array($decoded['authorized_tools'] ?? null)
                && $this->exactKeys($limits, ['max_provider_calls', 'max_tool_calls'])
                && ($limits['max_provider_calls'] ?? null) === ($route['max_provider_calls'] ?? null)
                && ($limits['max_tool_calls'] ?? null) === ($route['max_tool_calls'] ?? null);
        }
        if ($request->phase === ProviderRequest::PHASE_RESPONSE) {
            return ($decoded['instruction'] ?? null) === self::RESPONSE_INSTRUCTION
                && is_array($decoded['validated_decision'] ?? null)
                && is_array($decoded['binding_outcome'] ?? null)
                && is_array($decoded['step_outcomes'] ?? null)
                && $this->toolResultProjector->validateProjectedList($decoded['typed_tool_results'] ?? null);
        }
        return is_array($decoded['candidate_response'] ?? null)
            && $this->toolResultProjector->validateProjectedList($decoded['typed_tool_results'] ?? null)
            && is_array($decoded['binding_outcome'] ?? null)
            && is_array($decoded['step_outcomes'] ?? null);
    }

    /** @param array<int, array<string, mixed>> $input @return list<array<string, mixed>> */
    private function embeddedContextBundles(array $input): array
    {
        $bundles = [];
        foreach ($input as $item) {
            if (!is_array($item) || !is_string($item['text'] ?? null)) {
                continue;
            }
            $decoded = json_decode($item['text'], true, 128);
            if (!is_array($decoded)) {
                continue;
            }
            foreach (['context_bundle', 'bounded_context_bundle'] as $key) {
                if (is_array($decoded[$key] ?? null)) {
                    $bundles[] = $decoded[$key];
                }
            }
        }
        return $bundles;
    }

    private function readinessEnvelopeValid(ProviderRequest $request): bool
    {
        if ($request->phase !== ProviderRequest::PHASE_READINESS
            || $request->systemInstruction !== ProviderReadinessService::READINESS_SYSTEM_INSTRUCTION
            || !$this->exactKeys($request->metadata, ['purpose'])
            || ($request->metadata['purpose'] ?? null) !== ProviderRequest::PURPOSE_READINESS
            || $request->continuation !== null || $request->functionResults !== []
            || $request->timeoutSeconds !== 20
            || !$this->canonicalEqual($request->responseSchema, $this->payloadValidator->readinessSchema())
            || count($request->input) !== 1 || count($request->tools) !== 1
        ) {
            return false;
        }
        $input = $request->input[0];
        if (!is_array($input) || !$this->exactKeys($input, ['type', 'text']) || ($input['type'] ?? null) !== 'text'
            || !is_string($input['text'] ?? null)
            || preg_match('/^Capability probe nonce: ([a-f0-9]{16})$/D', $input['text'], $matches) !== 1
        ) {
            return false;
        }
        $nonce = $matches[1];
        $tool = $request->tools[0];
        $expectedSchema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['nonce'],
            'properties' => ['nonce' => ['type' => 'string', 'enum' => [$nonce]]],
        ];
        return is_array($tool)
            && $this->exactKeys($tool, ['name', 'version', 'description', 'input_schema'])
            && ($tool['name'] ?? null) === 'diagnostics.probe'
            && ($tool['version'] ?? null) === '1.0.0'
            && ($tool['description'] ?? null) === ProviderReadinessService::READINESS_TOOL_DESCRIPTION
            && ($tool['input_schema'] ?? null) === $expectedSchema;
    }

    /** @param array<int, array<string, mixed>> $tools @return array<int, array<string, mixed>> */
    private function mapTools(array $tools): array
    {
        return array_map(static fn (array $tool): array => [
            'type' => 'function',
            'name' => str_replace('.', '__', (string) ($tool['name'] ?? '')),
            'description' => (string) ($tool['description'] ?? ''),
            'parameters' => is_array($tool['input_schema'] ?? null) ? $tool['input_schema'] : ['type' => 'object'],
        ], $tools);
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private function exactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        return $actual === $keys;
    }

    private function canonicalEqual(mixed $left, mixed $right): bool
    {
        try {
            return hash_equals(CanonicalJson::encode($left), CanonicalJson::encode($right));
        } catch (\Throwable) {
            return false;
        }
    }

    private function expired(string $value): bool
    {
        try {
            return new \DateTimeImmutable($value) <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return true;
        }
    }
}
