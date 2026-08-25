<?php

declare(strict_types=1);

namespace Veyra\Identity\Infrastructure;

use Veyra\Identity\Application\ActorResolver;
use Veyra\Identity\Application\GuestSessionManager;
use Veyra\Identity\Domain\Actor;
use Veyra\Identity\Domain\ActorId;
use Veyra\Identity\Domain\ActorType;
use Veyra\Identity\Domain\CapabilityRegistry;

final class WordPressActorResolver implements ActorResolver
{
    private ?Actor $requestActor = null;

    public function __construct(
        private readonly GuestSessionManager $guestSessions,
        private readonly GuestCookieManager $cookies
    ) {
    }

    public function resolve(bool $allowGuest = true): ?Actor
    {
        if ($this->requestActor !== null) {
            return $allowGuest || $this->requestActor->type !== ActorType::Guest
                ? $this->requestActor
                : null;
        }

        $user = wp_get_current_user();

        if ($user instanceof \WP_User && $user->exists()) {
            $capabilities = [];

            foreach (CapabilityRegistry::names() as $capability) {
                if ($user->has_cap($capability)) {
                    $capabilities[] = $capability;
                }
            }

            $type = $capabilities === [] ? ActorType::Customer : ActorType::Staff;

            $this->requestActor = new Actor(
                new ActorId('wp-user-' . (string) $user->ID),
                $type,
                (int) $user->ID,
                null,
                $capabilities
            );
            return $this->requestActor;
        }

        if (!$allowGuest) {
            return null;
        }

        $rawToken = GuestCookieManager::readSessionToken();
        $context = $this->guestSessions->inspectFromRawToken($rawToken);

        if ($context === null) {
            return null;
        }

        $this->requestActor = new Actor(
            new ActorId($context->session->id->value()),
            ActorType::Guest,
            null,
            $context->session->id
        );
        return $this->requestActor;
    }

    public function resolveOrCreateGuest(): Actor
    {
        $resolved = $this->resolve(true);
        if ($resolved !== null) {
            return $resolved;
        }

        $created = $this->guestSessions->create();
        $context = $created['context'];
        $this->cookies->issue(
            $created['raw_token'],
            (string) $context->newCsrfToken,
            $context->session->expiresAt
        );

        $this->requestActor = new Actor(
            new ActorId($context->session->id->value()),
            ActorType::Guest,
            null,
            $context->session->id
        );

        return $this->requestActor;
    }
}
