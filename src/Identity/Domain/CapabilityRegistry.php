<?php

declare(strict_types=1);

namespace Veyra\Identity\Domain;

final class CapabilityRegistry
{
    /** @var list<string> */
    private const CAPABILITIES = [
        'manage_veyra_settings',
        'manage_veyra_agent',
        'manage_veyra_context_knowledge',
        'manage_veyra_experience',
        'manage_veyra_features',
        'manage_veyra_models',
        'view_veyra_dashboard',
        'view_veyra_conversations',
        'view_veyra_customer_identity',
        'view_veyra_attachments',
        'play_veyra_audio',
        'join_veyra_conversations',
        'pause_veyra_ai',
        'send_veyra_support_messages',
        'add_veyra_internal_notes',
        'view_veyra_crm',
        'manage_veyra_assigned_cases',
        'decide_veyra_cases',
        'execute_veyra_case_actions',
        'view_veyra_payment_evidence',
        'decide_veyra_payment_reviews',
        'execute_veyra_payment_transitions',
        'view_veyra_analytics',
        'view_veyra_audit',
        'export_veyra_conversations',
        'erase_veyra_data',
        'manage_veyra_retention',
        'manage_veyra_roles',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return self::CAPABILITIES;
    }

    public static function contains(string $name): bool
    {
        return in_array($name, self::CAPABILITIES, true);
    }

    /** @return list<Capability> */
    public static function all(): array
    {
        return array_map(static fn (string $name): Capability => new Capability($name), self::CAPABILITIES);
    }
}

