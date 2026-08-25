<?php
declare(strict_types=1);

namespace Veyra\AI\Provider;

/**
 * Single authority for deciding whether shopper data may reach the provider.
 * A capability probe is necessary, but it is deliberately not certification.
 */
final class ProviderReleaseGate
{
    public const ROUTE_ID = 'default_text_tool_orchestration';

    public function __construct(private readonly RouteManifest $manifest)
    {
    }

    /** @param array<string, mixed> $providerState @return array{allowed: bool, reason_code: string} */
    public function decision(array $providerState): array
    {
        try {
            $route = $this->manifest->route(self::ROUTE_ID);
        } catch (\Throwable) {
            return ['allowed' => false, 'reason_code' => 'provider_manifest_unavailable'];
        }

        $checkedAt = $this->checkedAt($providerState['checked_at'] ?? null);
        $maximumAge = (int) ($route['readiness_max_age_seconds'] ?? 86400);
        $fresh = $checkedAt !== null && $maximumAge >= 300 && $maximumAge <= 604800
            && $checkedAt <= new \DateTimeImmutable('+5 minutes', new \DateTimeZone('UTC'))
            && $checkedAt >= new \DateTimeImmutable('-' . $maximumAge . ' seconds', new \DateTimeZone('UTC'));
        $capabilities = is_array($providerState['capabilities'] ?? null) ? $providerState['capabilities'] : [];
        $requirements = [
            ['ok' => ($providerState['state'] ?? null) === 'Ready', 'reason' => 'provider_capability_readiness_required'],
            ['ok' => ($providerState['route_id'] ?? null) === self::ROUTE_ID
                && ($providerState['route_version'] ?? null) === $this->manifest->version(), 'reason' => 'provider_route_mismatch'],
            ['ok' => ($providerState['release_certified'] ?? false) === true, 'reason' => 'provider_release_certification_required'],
            ['ok' => $fresh, 'reason' => 'provider_readiness_stale'],
            ['ok' => $this->capabilitiesSatisfied($route['required_capabilities'] ?? null, $capabilities), 'reason' => 'provider_required_capability_missing'],
            ['ok' => ($route['status'] ?? null) === 'Ready'
                && ($route['release_certified'] ?? false) === true, 'reason' => 'provider_route_not_certified'],
            ['ok' => ($route['shopper_transmission_enabled'] ?? false) === true, 'reason' => 'provider_shopper_transmission_not_approved'],
            ['ok' => ($route['privacy_policy_published'] ?? false) === true, 'reason' => 'provider_privacy_policy_required'],
            ['ok' => ($route['evaluation_passed'] ?? false) === true, 'reason' => 'provider_evaluation_required'],
            ['ok' => ($route['context_manifest_persistence_certified'] ?? false) === true, 'reason' => 'provider_context_manifest_persistence_required'],
            ['ok' => ($route['prohibited_data_filter_certified'] ?? false) === true, 'reason' => 'provider_prohibited_data_filter_required'],
            ['ok' => ($route['provider_result_projection_certified'] ?? false) === true, 'reason' => 'provider_result_projection_required'],
            ['ok' => ($route['woocommerce_actor_binding_certified'] ?? false) === true, 'reason' => 'provider_woocommerce_actor_binding_required'],
            ['ok' => ($route['context_snapshot_consistency_certified'] ?? false) === true, 'reason' => 'provider_context_snapshot_consistency_required'],
        ];

        foreach ($requirements as $requirement) {
            if (!$requirement['ok']) {
                return ['allowed' => false, 'reason_code' => $requirement['reason']];
            }
        }

        return ['allowed' => true, 'reason_code' => 'runtime_ready'];
    }

    private function checkedAt(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || strlen($value) > 64
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1
        ) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param mixed $required @param array<string, mixed> $actual */
    private function capabilitiesSatisfied(mixed $required, array $actual): bool
    {
        if (!is_array($required) || ($required !== [] && array_is_list($required))) {
            return false;
        }
        foreach ($required as $name => $expectation) {
            if ($name === 'modalities') {
                if (!is_array($expectation) || !array_is_list($expectation)) {
                    return false;
                }
                foreach ($expectation as $modality) {
                    if (!is_string($modality) || ($actual[$modality] ?? false) !== true) {
                        return false;
                    }
                }
                continue;
            }
            if (!is_string($name) || $expectation !== true || ($actual[$name] ?? false) !== true) {
                return false;
            }
        }
        return true;
    }
}
