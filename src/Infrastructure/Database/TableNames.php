<?php

declare(strict_types=1);

namespace Veyra\Infrastructure\Database;

final class TableNames
{
    public function __construct(private readonly string $prefix)
    {
        if ($prefix === '' || preg_match('/^[A-Za-z0-9_]+$/D', $prefix) !== 1) {
            throw new \InvalidArgumentException('WordPress table prefix is invalid.');
        }
    }

    public function guestSessions(): string { return $this->prefix . 'veyra_guest_sessions'; }
    public function conversations(): string { return $this->prefix . 'veyra_conversations'; }
    public function messages(): string { return $this->prefix . 'veyra_messages'; }
    public function journeys(): string { return $this->prefix . 'veyra_journeys'; }
    public function conversationFocus(): string { return $this->prefix . 'veyra_conversation_focus'; }
    public function contextBundleManifests(): string { return $this->prefix . 'veyra_context_bundle_manifests'; }
    public function requirementStates(): string { return $this->prefix . 'veyra_requirement_states'; }
    public function pendingQuestions(): string { return $this->prefix . 'veyra_pending_questions'; }
    public function confirmations(): string { return $this->prefix . 'veyra_confirmations'; }
    public function idempotency(): string { return $this->prefix . 'veyra_idempotency'; }
    public function locks(): string { return $this->prefix . 'veyra_locks'; }
    public function audit(): string { return $this->prefix . 'veyra_audit'; }
    public function checkoutSessions(): string { return $this->prefix . 'veyra_checkout_sessions'; }
    public function cases(): string { return $this->prefix . 'veyra_cases'; }
    public function paymentReviews(): string { return $this->prefix . 'veyra_payment_reviews'; }
    public function attachments(): string { return $this->prefix . 'veyra_attachments'; }
    public function configurationRevisions(): string { return $this->prefix . 'veyra_configuration_revisions'; }
    public function rateLimits(): string { return $this->prefix . 'veyra_rate_limits'; }

    /** @return list<string> */
    public function all(): array
    {
        return [
            $this->guestSessions(),
            $this->conversations(),
            $this->messages(),
            $this->journeys(),
            $this->conversationFocus(),
            $this->contextBundleManifests(),
            $this->requirementStates(),
            $this->pendingQuestions(),
            $this->confirmations(),
            $this->idempotency(),
            $this->locks(),
            $this->audit(),
            $this->checkoutSessions(),
            $this->cases(),
            $this->paymentReviews(),
            $this->attachments(),
            $this->configurationRevisions(),
            $this->rateLimits(),
        ];
    }
}
