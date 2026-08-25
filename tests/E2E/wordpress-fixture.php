<?php

declare(strict_types=1);

/**
 * Browser-only fixture. It mounts the production customer presentation adapter
 * without enabling the production AI/provider feature gate.
 */
if (!defined('VEYRA_E2E_FIXTURE') || VEYRA_E2E_FIXTURE !== true) {
    return;
}

add_action('plugins_loaded', static function (): void {
    if (!defined('VEYRA_PLUGIN_FILE') || !class_exists(Veyra\Experience\Presentation\CustomerExperience::class)) {
        return;
    }

    (new Veyra\Experience\Presentation\CustomerExperience(
        (string) VEYRA_PLUGIN_FILE,
        static fn (): array => [
            'enabled' => true,
            'mount_launcher' => true,
            'ai_name' => 'Veyra test assistant',
            'ai_disclosure' => 'AI test fixture. No provider or commerce mutation is enabled.',
            'actor_scope' => 'guest:test-fixture',
            'capabilities' => [
                'new_conversation' => false,
                'stop_response' => false,
                'quick_replies' => true,
                'product_references' => true,
            ],
        ],
        (string) VEYRA_VERSION
    ))->register();
}, 30);
