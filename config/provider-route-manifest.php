<?php
declare(strict_types=1);

/**
 * Central provider release manifest.
 *
 * The candidate model was verified against Google's official model directory on
 * 2026-08-25. It remains unconfigured and uncertified until the merchant runs
 * readiness checks and the release evaluation suite passes.
 */
return [
    'manifest_version' => '2026.08.25.1',
    'proposal_version' => '4.1',
    'source_verification_date' => '2026-08-25',
    'routes' => [
        'default_text_tool_orchestration' => [
            'provider' => 'google_gemini',
            'api_surface' => 'interactions',
            // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Canonical v4.1 Gemini adapter route; WordPress 6.5-6.9 support precludes a WordPress 7-only client, and all transmission gates remain false.
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/interactions',
            'model_id' => 'gemini-3.7-flash',
            'adapter_version' => '1.1.0',
            'status' => 'Unconfigured',
            'store_requests' => false,
            'shopper_transmission_enabled' => false,
            'privacy_policy_published' => false,
            'evaluation_passed' => false,
            'context_manifest_persistence_certified' => false,
            'prohibited_data_filter_certified' => false,
            'provider_result_projection_certified' => false,
            'woocommerce_actor_binding_certified' => false,
            'context_snapshot_consistency_certified' => false,
            'readiness_max_age_seconds' => 86400,
            'timeout_seconds' => 25,
            // Up to two orchestration/repair calls plus one independent
            // semantic response-verification call.
            'max_provider_calls' => 3,
            'max_tool_calls' => 8,
            'max_context_bytes' => 65536,
            'max_context_items' => 256,
            'max_request_bytes' => 524288,
            'max_response_bytes' => 524288,
            'context_bundle_schema_version' => '1.1.0',
            'context_bundle_ttl_seconds' => 300,
            'shopper_purpose' => 'shopper_commerce_assistance',
            // This is a minimization allowlist, not transmission approval.
            // The independent flags above remain false until publication and
            // certification evidence exists.
            'allowed_data_classes' => [
                'internal',
                'personal',
                'commerce_confidential',
            ],
            'required_capabilities' => [
                'structured_output' => true,
                'function_calling' => true,
                'modalities' => ['text'],
            ],
            'release_certified' => false,
            'fallback_route' => null,
        ],
    ],
];
