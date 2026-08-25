<?php

declare(strict_types=1);

namespace Veyra\Features\Domain;

// Internal registry exceptions are never rendered; adapters escape at the output boundary.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class FeatureRegistry
{
    /** @var array<string, FeatureDefinition> */
    private array $definitions = [];

    /** @param iterable<FeatureDefinition> $definitions */
    public function __construct(iterable $definitions)
    {
        foreach ($definitions as $definition) {
            $key = $definition->key->value();

            if (isset($this->definitions[$key])) {
                throw new \InvalidArgumentException(sprintf('Duplicate feature key "%s".', $key));
            }

            $this->definitions[$key] = $definition;
        }

        foreach ($this->definitions as $definition) {
            foreach ($definition->dependencies as $dependency) {
                if (!isset($this->definitions[$dependency->value()])) {
                    throw new \InvalidArgumentException(sprintf('Unknown feature dependency "%s".', $dependency->value()));
                }
            }
        }
    }

    public function get(FeatureKey $key): FeatureDefinition
    {
        if (!isset($this->definitions[$key->value()])) {
            throw new \OutOfBoundsException(sprintf('Unknown feature "%s".', $key->value()));
        }

        return $this->definitions[$key->value()];
    }

    public function contains(FeatureKey $key): bool
    {
        return isset($this->definitions[$key->value()]);
    }

    /** @return list<FeatureDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /** @return list<FeatureDefinition> */
    public function byReleaseUnit(ReleaseUnit $releaseUnit): array
    {
        return array_values(
            array_filter(
                $this->definitions,
                static fn (FeatureDefinition $definition): bool => $definition->releaseUnit === $releaseUnit
            )
        );
    }

    public static function canonical(): self
    {
        $core = ReleaseUnit::ProductionCore;
        $optional = ReleaseUnit::OptionalModule;
        $key = static fn (string $value): FeatureKey => new FeatureKey($value);
        $definition = static fn (
            string $name,
            ReleaseUnit $unit,
            bool $defaultOn,
            bool $foundational,
            string $fallback,
            array $dependencies = []
        ): FeatureDefinition => new FeatureDefinition(
            $key($name),
            $unit,
            $defaultOn,
            $foundational,
            $fallback,
            array_map($key, $dependencies)
        );

        return new self([
            $definition('ai_semantic_orchestration', $core, true, true, 'Intelligent assistance is unavailable; show labeled native alternatives.'),
            $definition('ai_context_graph', $core, true, true, 'Block claims that cannot be grounded.', ['ai_semantic_orchestration']),
            $definition('ai_conversation_focus', $core, true, true, 'Ask a concise clarification instead of binding uncertain short replies.', ['ai_context_graph', 'ai_conversation_memory']),
            $definition('ai_merchant_knowledge', $core, false, false, 'Use current WooCommerce facts or state that policy evidence is unavailable.', ['ai_semantic_orchestration']),
            $definition('ai_conversation_memory', $core, true, true, 'Preserve current turn only and block unsafe resume.', ['ai_context_graph']),
            $definition('ai_cultural_profiles', $core, false, false, 'Use neutral respectful language.', ['ai_semantic_orchestration']),
            $definition('ai_location_awareness', $core, false, false, 'Request manual minimum-precision location.', ['ai_semantic_orchestration']),
            $definition('ai_time_awareness', $core, false, false, 'Block unverifiable time-sensitive claims.', ['ai_semantic_orchestration']),
            $definition('ai_multimodal_understanding', $core, false, false, 'Use text input.', ['ai_semantic_orchestration']),
            $definition('ai_proactive_next_action', $core, false, false, 'Continue without proactive suggestions.', ['ai_semantic_orchestration']),
            $definition('ai_human_handoff', $core, false, false, 'Expose an approved support route.', ['ai_conversation_memory']),
            $definition('commerce_product_assistance', $core, false, false, 'Use native WooCommerce catalog browsing.', ['ai_semantic_orchestration', 'ai_context_graph']),
            $definition('commerce_cart', $core, false, false, 'Use the native WooCommerce cart.', ['commerce_product_assistance']),
            $definition('commerce_chat_checkout', $core, false, false, 'Use native WooCommerce checkout.', ['commerce_cart', 'ai_conversation_focus']),
            $definition('commerce_order_service', $core, false, false, 'Use WooCommerce My Account orders.', ['ai_semantic_orchestration']),
            $definition('service_crm', $core, false, false, 'Use an approved human-support route.', ['ai_human_handoff']),
            $definition('payment_offline_review', $core, false, false, 'Use merchant-approved offline support.', ['service_crm']),
            $definition('chat_message_quoting', $core, false, false, 'Continue without quote attachment.', ['ai_conversation_focus']),
            $definition('chat_product_references', $core, false, false, 'Resolve the product again from authorized current state.', ['commerce_product_assistance']),
            $definition('operations_human_console', $core, false, false, 'Use ordinary WordPress administration.', ['ai_human_handoff']),
            $definition('shopper_guest_checkout', $optional, false, false, 'Login or use standard WooCommerce checkout.', ['commerce_chat_checkout']),
            $definition('shopper_saved_shortlist', $optional, false, false, 'Continue without a persistent shortlist.', ['commerce_product_assistance']),
            $definition('shopper_delivery_preview', $optional, false, false, 'State that a reliable preview is unavailable.', ['commerce_product_assistance']),
            $definition('shopper_persistent_comparison', $optional, false, false, 'Use a temporary comparison.', ['commerce_product_assistance']),
            $definition('shopper_recommendation_tuning', $optional, false, false, 'Use ordinary recommendation flow.', ['commerce_product_assistance']),
            $definition('shopper_address_autocomplete', $optional, false, false, 'Use manual address entry.', ['commerce_chat_checkout']),
            $definition('shopper_review_summaries', $optional, false, false, 'Show native reviews without AI summary.', ['commerce_product_assistance']),
            $definition('shopper_product_alerts', $optional, false, false, 'Do not create an alert.', ['commerce_product_assistance']),
            $definition('shopper_guided_bundles', $optional, false, false, 'Recommend individual products.', ['commerce_product_assistance', 'commerce_cart']),
            $definition('shopper_gift_mode', $optional, false, false, 'Use ordinary discovery.', ['commerce_product_assistance']),
            $definition('shopper_reorder_subscriptions', $optional, false, false, 'Offer validated one-time reorder or unavailable state.', ['commerce_order_service']),
            $definition('shopper_post_purchase', $optional, false, false, 'Use approved documentation or human support.', ['commerce_order_service']),
            $definition('shopper_returns_exchange', $optional, false, false, 'Use CRM or the standard return path.', ['commerce_order_service', 'service_crm']),
            $definition('shopper_loyalty_rewards', $optional, false, false, 'Continue without loyalty actions.', ['commerce_cart']),
            $definition('shopper_shareable_decisions', $optional, false, false, 'Keep decisions account-only.', ['ai_conversation_memory']),
            $definition('ai_customer_preference_memory', $optional, false, false, 'Use current-conversation memory only.', ['ai_conversation_memory']),
            $definition('ai_spoken_responses', $optional, false, false, 'Use matching text.', ['ai_multimodal_understanding']),
        ]);
    }
}
