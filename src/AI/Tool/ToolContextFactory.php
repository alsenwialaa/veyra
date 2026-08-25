<?php

declare(strict_types=1);

namespace Veyra\AI\Tool;

use Veyra\Features\Application\EffectiveFeatureStateService;
use Veyra\Features\Domain\FeatureRegistry;
use Veyra\Identity\Domain\Actor;
use Veyra\Identity\Domain\ActorType;

final class ToolContextFactory
{
    public function __construct(
        private readonly FeatureRegistry $features,
        private readonly EffectiveFeatureStateService $effectiveFeatures
    ) {
    }

    public function create(Actor $actor, string $conversationId, string $correlationId): ToolContext
    {
        $states = [];
        foreach ($this->features->all() as $feature) {
            $state = $this->effectiveFeatures->get($feature->key)->state->value;
            $states[$feature->key->value()] = ucfirst($state);
        }
        return new ToolContext(
            $this->actorType($actor),
            $actor->id->value(),
            $actor->wordpressUserId,
            $actor->guestSessionId?->value(),
            $conversationId,
            $actor->capabilities(),
            $states,
            function_exists('determine_locale') ? determine_locale() : 'en_US',
            $correlationId
        );
    }

    public function actorType(Actor $actor): string
    {
        if ($actor->type === ActorType::Guest) {
            return 'guest';
        }
        if ($actor->type === ActorType::Customer) {
            return 'customer';
        }
        if ($actor->hasCapability(new \Veyra\Identity\Domain\Capability('manage_veyra_settings'))) {
            return 'administrator';
        }
        if ($actor->hasCapability(new \Veyra\Identity\Domain\Capability('decide_veyra_cases'))) {
            return 'manager';
        }
        if ($actor->hasCapability(new \Veyra\Identity\Domain\Capability('decide_veyra_payment_reviews'))) {
            return 'reviewer';
        }
        return 'support';
    }
}
