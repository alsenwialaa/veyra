<?php

declare(strict_types=1);

namespace Veyra\Identity\Presentation;

use Veyra\Features\Application\FeatureGate;
use Veyra\Features\Domain\FeatureKey;
use Veyra\Identity\Application\ActorResolver;
use Veyra\Identity\Application\CapabilityPolicy;
use Veyra\Identity\Application\GuestSessionManager;
use Veyra\Identity\Domain\ActorType;
use Veyra\Identity\Domain\Capability;
use Veyra\Identity\Infrastructure\GuestCookieManager;

/**
 * Explicit REST permission callback infrastructure.
 *
 * Route handlers must still perform server-side ownership and current-state
 * checks in their application service. This gate never treats a client ID as
 * authorization.
 */
final class RestPermissionGate
{
    /** @var list<ActorType> */
    private readonly array $allowedActorTypes;

    public function __construct(
        private readonly ActorResolver $actors,
        private readonly CapabilityPolicy $capabilities,
        private readonly FeatureGate $features,
        private readonly GuestSessionManager $guestSessions,
        private readonly FeatureKey $feature,
        private readonly ?Capability $requiredCapability,
        private readonly bool $allowGuest,
        private readonly bool $mutation,
        ?array $allowedActorTypes = null
    ) {
        $allowedActorTypes ??= [ActorType::Guest, ActorType::Customer];
        if ($allowedActorTypes === []) {
            throw new \InvalidArgumentException('At least one REST actor type must be allowed.');
        }
        foreach ($allowedActorTypes as $type) {
            if (!$type instanceof ActorType) {
                throw new \InvalidArgumentException('REST actor types must use the ActorType contract.');
            }
        }
        $this->allowedActorTypes = array_values($allowedActorTypes);
    }

    /** @return bool|\WP_Error */
    public function __invoke(\WP_REST_Request $request): bool|\WP_Error
    {
        $featureState = $this->features->inspect($this->feature);

        if (!$featureState->usable()) {
            return $this->error($featureState->reasonCode, 503);
        }

        // Resolution is deliberately read-only. A cookie-less read may proceed
        // to the handler's rate-limited bootstrap boundary; mutation permission
        // never creates a guest session as a side effect of being denied.
        $actor = $this->actors->resolve($this->allowGuest);

        if ($actor === null) {
            if ($this->allowGuest && !$this->mutation && $this->requiredCapability === null) {
                return true;
            }

            return $this->error('veyra_authentication_required', 401);
        }

        if (!$this->allowGuest && $actor->type === ActorType::Guest) {
            return $this->error('veyra_authentication_required', 401);
        }

        if (!in_array($actor->type, $this->allowedActorTypes, true)) {
            return $this->error('veyra_actor_type_denied', 403);
        }

        if ($this->requiredCapability !== null && !$this->capabilities->allows($actor, $this->requiredCapability)) {
            return $this->error('veyra_capability_denied', 403);
        }

        if ($this->mutation && !$this->validMutationNonce($request, $actor->type)) {
            return $this->error('veyra_csrf_check_failed', 403);
        }

        return true;
    }

    private function validMutationNonce(\WP_REST_Request $request, ActorType $type): bool
    {
        if ($type !== ActorType::Guest) {
            $nonce = $request->get_header('X-WP-Nonce');

            return is_string($nonce) && $nonce !== '' && wp_verify_nonce($nonce, 'wp_rest') === 1;
        }

        $rawSessionToken = GuestCookieManager::readSessionToken();
        $context = $this->guestSessions->inspectFromRawToken($rawSessionToken);
        $csrf = $request->get_header('X-Veyra-CSRF');

        return $context !== null
            && is_string($csrf)
            && $this->guestSessions->verifyCsrf($context->session, $csrf);
    }

    private function error(string $code, int $status): \WP_Error
    {
        return new \WP_Error(
            $code,
            __('The Veyra request is not authorized for the current actor or state.', 'veyra-ai-commerce-agent'),
            ['status' => $status]
        );
    }
}
