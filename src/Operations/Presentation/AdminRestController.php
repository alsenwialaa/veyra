<?php

declare(strict_types=1);

namespace Veyra\Operations\Presentation;

use Veyra\AI\Provider\CredentialVault;
use Veyra\AI\Provider\ProviderReadinessService;
use Veyra\AI\Provider\ProviderReleaseGate;
use Veyra\Audit\Application\AuditWriter;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Domain\IdempotencyDecision;
use Veyra\Confirmation\Domain\IdempotencyDecisionStatus;
use Veyra\Features\Application\RuntimeFeatureRegistry;
use Veyra\Features\Domain\FeatureKey;
use Veyra\Features\Domain\FeatureState;
use Veyra\Http\Correlation;
use Veyra\Http\RestEnvelope;
use Veyra\Identity\Application\ActorResolver;
use Veyra\Identity\Domain\Actor;
use Veyra\Operations\Configuration\AdminProductService;
use Veyra\Shared\Domain\CorrelationId;

final class AdminRestController
{
    public function __construct(
        private readonly AdminProductService $products,
        private readonly CredentialVault $credentials,
        private readonly ProviderReadinessService $readiness,
        private readonly ProviderReleaseGate $releaseGate,
        private readonly RuntimeFeatureRegistry $runtimeFeatures,
        private readonly ActorResolver $actors,
        private readonly AuditWriter $audit,
        private readonly IdempotencyService $idempotency
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('veyra/v1', '/admin/products/(?P<product>agent|knowledge|experience|commerce|operations)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'getProduct'],
            'permission_callback' => [$this, 'canReadProduct'],
        ]);
        register_rest_route('veyra/v1', '/admin/products/(?P<product>agent|knowledge|experience|commerce)/draft', [
            'methods' => 'PATCH',
            'callback' => [$this, 'saveDraft'],
            'permission_callback' => [$this, 'canMutateProduct'],
        ]);
        foreach (['validate', 'simulate', 'publish', 'rollback'] as $action) {
            register_rest_route('veyra/v1', '/admin/products/(?P<product>agent|knowledge|experience|commerce)/' . $action, [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, $action],
                'permission_callback' => [$this, 'canMutateProduct'],
            ]);
        }
        register_rest_route('veyra/v1', '/admin/provider', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'getProvider'],
            'permission_callback' => [$this, 'canManageProvider'],
        ]);
        register_rest_route('veyra/v1', '/admin/provider/credential', [
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'saveCredential'],
                'permission_callback' => [$this, 'canManageProvider'],
            ],
            [
                'methods' => \WP_REST_Server::DELETABLE,
                'callback' => [$this, 'clearCredential'],
                'permission_callback' => [$this, 'canManageProvider'],
            ],
        ]);
        register_rest_route('veyra/v1', '/admin/provider/readiness', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'runReadiness'],
            'permission_callback' => [$this, 'canManageProvider'],
        ]);
    }

    /** @return bool|\WP_Error */
    public function canReadProduct(\WP_REST_Request $request): bool|\WP_Error
    {
        $product = (string) $request->get_param('product');
        $capability = AdminProductService::CAPABILITIES[$product] ?? null;
        return is_string($capability) && current_user_can($capability)
            ? true
            : $this->permissionError('veyra_admin_capability_denied');
    }

    /** @return bool|\WP_Error */
    public function canMutateProduct(\WP_REST_Request $request): bool|\WP_Error
    {
        $read = $this->canReadProduct($request);
        if ($read !== true) {
            return $read;
        }
        return $this->validNonce($request) ? true : $this->permissionError('veyra_admin_csrf_failed');
    }

    /** @return bool|\WP_Error */
    public function canManageProvider(\WP_REST_Request $request): bool|\WP_Error
    {
        if (!current_user_can('manage_veyra_models')) {
            return $this->permissionError('veyra_provider_capability_denied');
        }
        return $request->get_method() === 'GET' || $this->validNonce($request)
            ? true
            : $this->permissionError('veyra_admin_csrf_failed');
    }

    public function getProduct(\WP_REST_Request $request): \WP_REST_Response
    {
        $correlation = Correlation::forRequest($request);
        try {
            $state = $this->products->state((string) $request->get_param('product'), get_current_user_id());
            return $this->response(RestEnvelope::succeeded('admin_state_loaded', ['state' => $state], $correlation->value()));
        } catch (\Throwable) {
            return $this->response(RestEnvelope::failed('admin_state_unavailable', 'Authoritative administration state is unavailable.', $correlation->value()), 503);
        }
    }

    public function saveDraft(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->runProductCommand($request, 'save_draft');
    }

    public function validate(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->runProductCommand($request, 'validate');
    }

    public function simulate(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->runProductCommand($request, 'simulate');
    }

    public function publish(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->runProductCommand($request, 'publish');
    }

    public function rollback(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->runProductCommand($request, 'rollback');
    }

    public function getProvider(\WP_REST_Request $request): \WP_REST_Response
    {
        $correlation = Correlation::forRequest($request);
        $state = $this->readiness->current();
        $state['credential_configured'] = $this->credentials->hasGeminiCredential();
        $state['runtime_activation'] = $this->releaseGate->decision($state);
        return $this->response(RestEnvelope::succeeded('provider_state_loaded', ['provider' => $state], $correlation->value()));
    }

    public function saveCredential(\WP_REST_Request $request): \WP_REST_Response
    {
        $correlation = Correlation::forRequest($request);
        $actor = $this->actors->resolve(false);
        $body = $request->get_json_params();
        if ($actor === null || !$this->validProviderCommand($body, 'credential_store')) {
            return $this->response(RestEnvelope::blocked('provider_credential_invalid', 'A valid provider credential is required.', $correlation->value()), 400);
        }
        $apiKey = (string) $body['api_key'];
        $claim = $this->claimWrite($request, $actor, 'provider.credential.store', $body, 'provider:google_gemini', $correlation);
        if ($claim instanceof \WP_REST_Response) {
            $apiKey = '';
            return $claim;
        }
        try {
            $this->credentials->storeGeminiCredential($apiKey);
            $this->readiness->block('provider_readiness_test_required');
            $this->blockAiRuntime('provider_readiness_test_required');
            $this->audit->writeRequired($actor, 'provider.credential.store', 'provider', 'google_gemini', 'credential_stored', $correlation);
            return $this->finishWrite(
                $claim,
                RestEnvelope::succeeded('provider_credential_stored', ['configured' => true, 'readiness' => 'Blocked'], $correlation->value()),
                200,
                true,
                $correlation
            );
        } catch (\InvalidArgumentException) {
            return $this->finishWrite(
                $claim,
                RestEnvelope::blocked('provider_credential_invalid', 'The provider credential did not pass bounded validation.', $correlation->value(), 'safe_no_side_effect'),
                400,
                false,
                $correlation
            );
        } catch (\Throwable) {
            $this->markWriteUncertain($claim, 'provider_credential_outcome_uncertain');
            return $this->response(RestEnvelope::uncertain('provider_credential_outcome_uncertain', 'Credential state could not be fully verified. Reload provider state before retrying.', $correlation->value()), 503);
        } finally {
            $apiKey = '';
        }
    }

    public function clearCredential(\WP_REST_Request $request): \WP_REST_Response
    {
        $correlation = Correlation::forRequest($request);
        $actor = $this->actors->resolve(false);
        $body = $request->get_json_params();
        if ($actor === null) {
            return $this->response(RestEnvelope::blocked('provider_actor_unavailable', 'The current administrator could not be resolved.', $correlation->value()), 401);
        }
        if (!$this->validProviderCommand($body, 'credential_clear')) {
            return $this->response(RestEnvelope::blocked('provider_command_invalid', 'The provider command did not match its public contract.', $correlation->value(), 'safe_no_side_effect'), 400);
        }
        $claim = $this->claimWrite($request, $actor, 'provider.credential.clear', $body, 'provider:google_gemini', $correlation);
        if ($claim instanceof \WP_REST_Response) {
            return $claim;
        }
        try {
            $this->credentials->clearGeminiCredential();
            $this->readiness->block('provider_unconfigured');
            $this->blockAiRuntime('provider_unconfigured');
            $this->audit->writeRequired($actor, 'provider.credential.clear', 'provider', 'google_gemini', 'credential_cleared', $correlation);
            return $this->finishWrite(
                $claim,
                RestEnvelope::succeeded('provider_credential_cleared', ['configured' => false, 'readiness' => 'Blocked'], $correlation->value()),
                200,
                true,
                $correlation
            );
        } catch (\Throwable) {
            $this->markWriteUncertain($claim, 'provider_credential_clear_uncertain');
            return $this->response(RestEnvelope::uncertain('provider_credential_clear_uncertain', 'Credential removal could not be fully verified. Reload provider state.', $correlation->value()), 503);
        }
    }

    public function runReadiness(\WP_REST_Request $request): \WP_REST_Response
    {
        $correlation = Correlation::forRequest($request);
        $actor = $this->actors->resolve(false);
        $body = $request->get_json_params();
        if ($actor === null) {
            return $this->response(RestEnvelope::blocked('provider_actor_unavailable', 'The current administrator could not be resolved.', $correlation->value()), 401);
        }
        if (!$this->validProviderCommand($body, 'readiness')) {
            return $this->response(RestEnvelope::blocked('provider_command_invalid', 'The provider command did not match its public contract.', $correlation->value(), 'safe_no_side_effect'), 400);
        }
        $claim = $this->claimWrite($request, $actor, 'provider.readiness.test', $body, 'provider:google_gemini', $correlation);
        if ($claim instanceof \WP_REST_Response) {
            return $claim;
        }
        try {
            $state = $this->readiness->runExplicitTest();
            $probeReady = ($state['state'] ?? null) === 'Ready';
            $activation = $this->releaseGate->decision($state);
            $runtimeReady = $probeReady && $activation['allowed'];
            $state['runtime_activation'] = $activation;
            if ($runtimeReady) {
                $this->enableAiRuntime();
            } else {
                $this->blockAiRuntime($probeReady
                    ? $activation['reason_code']
                    : (string) ($state['safe_error_code'] ?? 'provider_readiness_failed'));
            }
            $auditCode = $runtimeReady
                ? 'readiness_and_release_passed'
                : ($probeReady ? 'capability_probe_passed_release_blocked' : 'readiness_blocked');
            $this->audit->writeRequired($actor, 'provider.readiness.test', 'provider', 'google_gemini', $auditCode, $correlation, [
                'route_version' => (string) ($state['route_version'] ?? ''),
                'state' => (string) ($state['state'] ?? 'Blocked'),
                'runtime_activation' => $runtimeReady ? 'On' : 'Blocked',
            ]);
            $envelope = $runtimeReady
                ? RestEnvelope::succeeded('provider_readiness_and_release_passed', ['provider' => $state], $correlation->value())
                : ($probeReady
                    ? RestEnvelope::succeeded('provider_capability_probe_passed_release_blocked', ['provider' => $state], $correlation->value())
                    : RestEnvelope::blocked('provider_readiness_blocked', 'The provider did not pass the required structured-output and tool-call checks.', $correlation->value(), 'safe_no_side_effect'));
            return $this->finishWrite($claim, $envelope, $probeReady ? 200 : 422, $probeReady, $correlation);
        } catch (\Throwable) {
            $this->blockAiRuntime('provider_readiness_test_failed');
            $this->markWriteUncertain($claim, 'provider_readiness_test_failed');
            return $this->response(RestEnvelope::failed('provider_readiness_test_failed', 'The explicit provider test could not complete safely.', $correlation->value()), 503);
        }
    }

    private function runProductCommand(\WP_REST_Request $request, string $action): \WP_REST_Response
    {
        $correlation = Correlation::forRequest($request);
        $actor = $this->actors->resolve(false);
        $product = (string) $request->get_param('product');
        $body = $request->get_json_params();
        if ($actor === null || !$this->validProductCommand($body, $product, $action)) {
            return $this->response(RestEnvelope::blocked('admin_command_invalid', 'The administration command did not match its public contract.', $correlation->value()), 400);
        }
        $claim = $this->claimWrite(
            $request,
            $actor,
            'admin.' . $action,
            $body,
            'configuration:' . $product,
            $correlation
        );
        if ($claim instanceof \WP_REST_Response) {
            return $claim;
        }
        try {
            $draftVersion = is_string($body['expected_draft_version'] ?? null) ? $body['expected_draft_version'] : null;
            $publishedVersion = is_string($body['expected_published_version'] ?? null) ? $body['expected_published_version'] : null;
            $result = match ($action) {
                'save_draft' => is_array($body['configuration'] ?? null)
                    ? $this->products->saveDraft($product, $body['configuration'], $draftVersion, (int) $actor->wordpressUserId)
                    : ['ok' => false, 'code' => 'configuration_invalid'],
                'validate' => $this->products->validateDraft($product, $draftVersion, (int) $actor->wordpressUserId),
                'simulate' => $this->products->simulate($product, $draftVersion, (int) $actor->wordpressUserId),
                'publish' => $this->products->publish($product, $draftVersion, $publishedVersion, (int) $actor->wordpressUserId),
                'rollback' => $this->products->rollback($product, $publishedVersion, (int) $actor->wordpressUserId),
                default => ['ok' => false, 'code' => 'admin_action_unknown'],
            };
            $ok = ($result['ok'] ?? false) === true;
            $code = is_string($result['code'] ?? null) ? $result['code'] : 'admin_command_failed';
            $this->audit->writeRequired($actor, 'admin.' . $action, 'configuration', $product, $code, $correlation, [
                'product' => $product,
                'succeeded' => $ok,
            ]);
            if ($ok) {
                return $this->finishWrite(
                    $claim,
                    RestEnvelope::succeeded($code, ['result' => $result], $correlation->value()),
                    200,
                    true,
                    $correlation
                );
            }
            $status = str_contains($code, 'conflict') ? 409 : 422;
            $message = str_contains($code, 'conflict')
                ? 'The configuration changed elsewhere. Reload before retrying.'
                : 'The server blocked this administration action. No success is claimed.';
            return $this->finishWrite(
                $claim,
                RestEnvelope::blocked($code, $message, $correlation->value(), 'safe_no_side_effect'),
                $status,
                false,
                $correlation
            );
        } catch (\Throwable) {
            $this->markWriteUncertain($claim, 'admin_command_outcome_uncertain');
            return $this->response(RestEnvelope::uncertain('admin_command_outcome_uncertain', 'The command outcome could not be fully verified. Reload authoritative state.', $correlation->value()), 503);
        }
    }

    /** @param array<string, mixed> $payload */
    private function claimWrite(
        \WP_REST_Request $request,
        Actor $actor,
        string $action,
        array $payload,
        string $resourceScope,
        CorrelationId $correlation
    ): IdempotencyDecision|\WP_REST_Response {
        $key = $request->get_header('Idempotency-Key');
        if (!is_string($key) || strlen($key) < 8 || strlen($key) > 191) {
            return $this->response(RestEnvelope::blocked(
                'idempotency_key_invalid',
                'A bounded idempotency key is required for this administration write.',
                $correlation->value(),
                'safe_no_side_effect'
            ), 400);
        }

        try {
            $decision = $this->idempotency->begin($actor, $action, $key, $payload, $resourceScope, $correlation);
        } catch (\InvalidArgumentException) {
            return $this->response(RestEnvelope::blocked(
                'idempotency_key_invalid',
                'The idempotency envelope is invalid.',
                $correlation->value(),
                'safe_no_side_effect'
            ), 400);
        } catch (\Throwable) {
            return $this->response(RestEnvelope::uncertain(
                'idempotency_unavailable',
                'The write could not be claimed safely. Do not retry until authoritative state is refreshed.',
                $correlation->value()
            ), 503);
        }

        if ($decision->status === IdempotencyDecisionStatus::Claimed) {
            return $decision;
        }

        if ($decision->status === IdempotencyDecisionStatus::Conflict) {
            return $this->response(RestEnvelope::blocked(
                'idempotency_payload_conflict',
                'This idempotency key was already used for different input.',
                $correlation->value(),
                'never_retry'
            ), 409);
        }

        if ($decision->status === IdempotencyDecisionStatus::Replay
            && is_array($decision->record->result)
            && is_array($decision->record->result['envelope'] ?? null)
            && is_int($decision->record->result['http_status'] ?? null)
        ) {
            return $this->response(
                $decision->record->result['envelope'],
                max(200, min(599, $decision->record->result['http_status']))
            );
        }

        return $this->response(RestEnvelope::make(
            'uncertain',
            $decision->code,
            ['message' => 'The prior write must be reconciled before retrying.'],
            $correlation->value(),
            'reconcile_before_retry'
        ), 409);
    }

    /** @param array<string, mixed> $envelope */
    private function finishWrite(
        IdempotencyDecision $decision,
        array $envelope,
        int $httpStatus,
        bool $succeeded,
        CorrelationId $correlation
    ): \WP_REST_Response {
        $stored = ['http_status' => $httpStatus, 'envelope' => $envelope];
        try {
            $persisted = $succeeded
                ? $this->idempotency->complete($decision->record, (string) $envelope['code'], $stored, true)
                : $this->idempotency->fail($decision->record, (string) $envelope['code'], $stored, true);
        } catch (\Throwable) {
            $persisted = false;
        }
        if (!$persisted) {
            $this->markWriteUncertain($decision, 'admin_idempotency_completion_uncertain');
            return $this->response(RestEnvelope::uncertain(
                'admin_idempotency_completion_uncertain',
                'The operation result could not be made replay-safe. Reload authoritative state before retrying.',
                $correlation->value()
            ), 503);
        }

        return $this->response($envelope, $httpStatus);
    }

    private function markWriteUncertain(IdempotencyDecision $decision, string $code): void
    {
        try {
            $this->idempotency->markUncertain($decision->record, $code, []);
        } catch (\Throwable) {
            // The customer-visible result remains uncertain even if persistence
            // health prevents recording additional reconciliation metadata.
        }
    }

    /** @param mixed $body */
    private function validProviderCommand(mixed $body, string $command): bool
    {
        if (!is_array($body) || ($body['provider'] ?? null) !== 'gemini') {
            return false;
        }

        $expected = match ($command) {
            'credential_store' => ['api_key', 'provider', 'schema_version'],
            'credential_clear' => ['provider', 'schema_version'],
            'readiness' => ['provider', 'schema_version'],
            default => [],
        };
        $keys = array_keys($body);
        sort($keys);
        if ($expected === [] || $keys !== $expected) {
            return false;
        }
        if ($command === 'readiness') {
            return ($body['schema_version'] ?? null) === 'veyra.provider_readiness_command.v1';
        }
        if (($body['schema_version'] ?? null) !== 'veyra.provider_credential_command.v1') {
            return false;
        }

        return $command !== 'credential_store'
            || (is_string($body['api_key'] ?? null)
                && strlen($body['api_key']) >= 8
                && strlen($body['api_key']) <= 4096
                && preg_match('/[\x00-\x1F\x7F]/', $body['api_key']) !== 1);
    }

    /** @param mixed $body */
    private function validProductCommand(mixed $body, string $product, string $action): bool
    {
        if (!is_array($body)
            || ($body['schema_version'] ?? null) !== 'veyra.admin_product_command.v1'
            || ($body['product'] ?? null) !== $product
            || ($body['action'] ?? null) !== $action
        ) {
            return false;
        }
        $allowed = [
            'schema_version',
            'product',
            'action',
            'expected_draft_version',
            'expected_published_version',
            'configuration',
            'activation_at',
        ];
        if (array_diff(array_keys($body), $allowed) !== []) {
            return false;
        }
        foreach (['expected_draft_version', 'expected_published_version', 'activation_at'] as $field) {
            if (array_key_exists($field, $body) && $body[$field] !== null && !is_string($body[$field])) {
                return false;
            }
        }
        if (isset($body['configuration']) && !is_array($body['configuration'])) {
            return false;
        }

        return $action !== 'save_draft' || isset($body['configuration']);
    }

    private function enableAiRuntime(): void
    {
        foreach (['ai_semantic_orchestration', 'ai_context_graph', 'ai_conversation_memory', 'ai_conversation_focus'] as $feature) {
            $this->runtimeFeatures->register(new FeatureKey($feature), FeatureState::On, 'runtime_ready');
        }
    }

    private function blockAiRuntime(string $reason): void
    {
        foreach (['ai_semantic_orchestration', 'ai_context_graph', 'ai_conversation_memory', 'ai_conversation_focus'] as $feature) {
            $this->runtimeFeatures->register(new FeatureKey($feature), FeatureState::Blocked, $reason);
        }
    }

    private function validNonce(\WP_REST_Request $request): bool
    {
        $nonce = $request->get_header('X-WP-Nonce');
        return is_string($nonce) && $nonce !== '' && wp_verify_nonce($nonce, 'wp_rest') === 1;
    }

    /** @return \WP_Error */
    private function permissionError(string $code): \WP_Error
    {
        return new \WP_Error($code, __('This Veyra administration request is not authorized.', 'veyra-ai-commerce-agent'), ['status' => 403]);
    }

    /** @param array<string, mixed> $envelope */
    private function response(array $envelope, int $status = 200): \WP_REST_Response
    {
        $response = new \WP_REST_Response($envelope, $status);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }
}
