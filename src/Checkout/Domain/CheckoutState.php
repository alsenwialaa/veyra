<?php

declare(strict_types=1);

namespace Veyra\Checkout\Domain;

use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Shared\Domain\CanonicalJson;
use Veyra\Shared\Domain\StateHash;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Shared\Domain\Uuid;

/**
 * Actor-owned, resumable checkout intent state.
 *
 * This is deliberately not a parallel commerce ledger. Prices, stock, shipping,
 * tax, payment eligibility and the cart are refreshed from WooCommerce before a
 * preview is emitted. The state only records the shopper's validated choices.
 */
final class CheckoutState implements \JsonSerializable
{
    /**
     * @param array<string, array<string, string>> $contacts
     * @param array<string, string>                $billingAddress
     * @param array<string, string>                $shippingAddress
     * @param array<string, mixed>                 $packageSelection
     * @param array<string, mixed>                 $totals
     */
    private function __construct(
        public readonly string $id,
        public readonly string $conversationId,
        public readonly ?string $journeyId,
        public readonly ActorScope $actor,
        public readonly string $cartHash,
        public readonly ?string $fulfillmentMode,
        public readonly array $contacts,
        public readonly array $billingAddress,
        public readonly array $shippingAddress,
        public readonly array $packageSelection,
        public readonly ?string $paymentMethodId,
        public readonly array $totals,
        public readonly string $status,
        public readonly int $version,
        public readonly UtcInstant $expiresAt,
        public readonly UtcInstant $createdAt,
        public readonly UtcInstant $updatedAt,
        private readonly StateHash $stateHash
    ) {
        if (!Uuid::isValid($id)) {
            throw new \InvalidArgumentException('Checkout state ID must be a UUIDv4.');
        }
        self::assertIdentifier($conversationId, 'conversation');
        if ($journeyId !== null) {
            self::assertIdentifier($journeyId, 'journey');
        }
        if ($cartHash === '' || strlen($cartHash) > 64 || preg_match('/[\x00-\x1F\x7F]/', $cartHash) === 1) {
            throw new \InvalidArgumentException('Checkout cart hash is invalid.');
        }
        if ($fulfillmentMode !== null && preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $fulfillmentMode) !== 1) {
            throw new \InvalidArgumentException('Checkout fulfillment mode is invalid.');
        }
        if ($paymentMethodId !== null && ($paymentMethodId === '' || strlen($paymentMethodId) > 191)) {
            throw new \InvalidArgumentException('Checkout payment method ID is invalid.');
        }
        if (!in_array($status, ['active', 'stale', 'expired'], true) || $version < 1) {
            throw new \InvalidArgumentException('Checkout lifecycle state is invalid.');
        }
    }

    public static function open(
        ActorScope $actor,
        string $conversationId,
        string $cartHash,
        UtcInstant $now,
        int $lifetimeSeconds,
        ?string $journeyId = null
    ): self {
        $expiresAt = $now->addSeconds(self::boundedLifetime($lifetimeSeconds));
        $id = Uuid::v4();
        $hash = self::hashFor(
            $id,
            $conversationId,
            $journeyId,
            $actor,
            $cartHash,
            null,
            [],
            [],
            [],
            [],
            null,
            [],
            'active',
            1,
            $expiresAt
        );

        return new self(
            $id,
            $conversationId,
            $journeyId,
            $actor,
            $cartHash,
            null,
            [],
            [],
            [],
            [],
            null,
            [],
            'active',
            1,
            $expiresAt,
            $now,
            $now,
            $hash
        );
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $actor = new ActorScope((string) ($row['actor_type'] ?? ''), (string) ($row['actor_id'] ?? ''));
        if (!hash_equals($actor->hash(), (string) ($row['actor_key_hash'] ?? ''))) {
            throw new \UnexpectedValueException('Checkout actor scope digest does not match the row.');
        }

        $state = new self(
            (string) ($row['public_id'] ?? ''),
            (string) ($row['conversation_id'] ?? ''),
            self::nullableString($row['journey_id'] ?? null),
            $actor,
            (string) ($row['cart_hash'] ?? ''),
            self::nullableString($row['fulfillment_mode'] ?? null),
            self::decodeObject($row['contact_json'] ?? null),
            self::stringMap(self::decodeObject($row['billing_address_json'] ?? null)),
            self::stringMap(self::decodeObject($row['shipping_address_json'] ?? null)),
            self::decodeObject($row['package_selection_json'] ?? null),
            self::nullableString($row['payment_method_id'] ?? null),
            self::decodeObject($row['totals_json'] ?? null),
            (string) ($row['status'] ?? ''),
            (int) ($row['version'] ?? 0),
            UtcInstant::fromDatabase((string) ($row['expires_at'] ?? '')),
            UtcInstant::fromDatabase((string) ($row['created_at'] ?? '')),
            UtcInstant::fromDatabase((string) ($row['updated_at'] ?? '')),
            new StateHash((string) ($row['state_hash'] ?? ''))
        );

        $expected = self::hashFor(
            $state->id,
            $state->conversationId,
            $state->journeyId,
            $state->actor,
            $state->cartHash,
            $state->fulfillmentMode,
            $state->contacts,
            $state->billingAddress,
            $state->shippingAddress,
            $state->packageSelection,
            $state->paymentMethodId,
            $state->totals,
            $state->status,
            $state->version,
            $state->expiresAt
        );
        if (!$state->stateHash->equals($expected)) {
            throw new \UnexpectedValueException('Checkout state digest does not match the persisted values.');
        }

        return $state;
    }

    /**
     * Evolves only explicitly supported checkout state fields. Passing null is
     * meaningful and clears a nullable field; omitted keys remain unchanged.
     *
     * @param array<string, mixed> $changes
     */
    public function evolve(array $changes, UtcInstant $now, int $lifetimeSeconds): self
    {
        $allowed = [
            'journey_id', 'cart_hash', 'fulfillment_mode', 'contacts', 'billing_address',
            'shipping_address', 'package_selection', 'payment_method_id', 'totals', 'status',
        ];
        if (array_diff(array_keys($changes), $allowed) !== []) {
            throw new \InvalidArgumentException('Checkout mutation contains an unsupported field.');
        }

        $journeyId = array_key_exists('journey_id', $changes) ? self::nullableString($changes['journey_id']) : $this->journeyId;
        $cartHash = array_key_exists('cart_hash', $changes) ? (string) $changes['cart_hash'] : $this->cartHash;
        $fulfillment = array_key_exists('fulfillment_mode', $changes) ? self::nullableString($changes['fulfillment_mode']) : $this->fulfillmentMode;
        $contacts = array_key_exists('contacts', $changes) && is_array($changes['contacts']) ? $changes['contacts'] : $this->contacts;
        $billing = array_key_exists('billing_address', $changes) && is_array($changes['billing_address']) ? self::stringMap($changes['billing_address']) : $this->billingAddress;
        $shipping = array_key_exists('shipping_address', $changes) && is_array($changes['shipping_address']) ? self::stringMap($changes['shipping_address']) : $this->shippingAddress;
        $packages = array_key_exists('package_selection', $changes) && is_array($changes['package_selection']) ? $changes['package_selection'] : $this->packageSelection;
        $payment = array_key_exists('payment_method_id', $changes) ? self::nullableString($changes['payment_method_id']) : $this->paymentMethodId;
        $totals = array_key_exists('totals', $changes) && is_array($changes['totals']) ? $changes['totals'] : $this->totals;
        $status = array_key_exists('status', $changes) ? (string) $changes['status'] : $this->status;
        $version = $this->version + 1;
        $expiresAt = $now->addSeconds(self::boundedLifetime($lifetimeSeconds));
        $hash = self::hashFor(
            $this->id,
            $this->conversationId,
            $journeyId,
            $this->actor,
            $cartHash,
            $fulfillment,
            $contacts,
            $billing,
            $shipping,
            $packages,
            $payment,
            $totals,
            $status,
            $version,
            $expiresAt
        );

        return new self(
            $this->id,
            $this->conversationId,
            $journeyId,
            $this->actor,
            $cartHash,
            $fulfillment,
            $contacts,
            $billing,
            $shipping,
            $packages,
            $payment,
            $totals,
            $status,
            $version,
            $expiresAt,
            $this->createdAt,
            $now,
            $hash
        );
    }

    public function stateHash(): StateHash
    {
        return $this->stateHash;
    }

    public function isExpiredAt(UtcInstant $now): bool
    {
        return $this->status === 'expired' || $this->expiresAt->isAtOrBefore($now);
    }

    public function hasCurrentCart(string $cartHash): bool
    {
        return hash_equals($this->cartHash, $cartHash);
    }

    /** @return array<string, mixed> */
    public function persistenceValues(): array
    {
        return [
            'public_id' => $this->id,
            'conversation_id' => $this->conversationId,
            'journey_id' => $this->journeyId,
            'actor_type' => $this->actor->actorType,
            'actor_id' => $this->actor->actorId,
            'actor_key_hash' => $this->actor->hash(),
            'cart_hash' => $this->cartHash,
            'fulfillment_mode' => $this->fulfillmentMode,
            'contact_json' => CanonicalJson::encode($this->contacts),
            'billing_address_json' => CanonicalJson::encode($this->billingAddress),
            'shipping_address_json' => CanonicalJson::encode($this->shippingAddress),
            'package_selection_json' => CanonicalJson::encode($this->packageSelection),
            'payment_method_id' => $this->paymentMethodId,
            'totals_json' => CanonicalJson::encode($this->totals),
            'state_hash' => $this->stateHash->value(),
            'status' => $this->status,
            'version' => $this->version,
            'expires_at' => $this->expiresAt->toDatabase(),
            'created_at' => $this->createdAt->toDatabase(),
            'updated_at' => $this->updatedAt->toDatabase(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'checkout_session_id' => $this->id,
            'conversation_id' => $this->conversationId,
            'journey_id' => $this->journeyId,
            'cart_hash' => $this->cartHash,
            'fulfillment_mode' => $this->fulfillmentMode,
            'contacts' => $this->contacts,
            'billing_address' => $this->billingAddress,
            'shipping_address' => $this->shippingAddress,
            'package_selection' => $this->packageSelection,
            'payment_method_id' => $this->paymentMethodId,
            'totals' => $this->totals,
            'status' => $this->status,
            'version' => $this->version,
            'state_hash' => $this->stateHash->value(),
            'expires_at' => $this->expiresAt->toIso8601(),
            'updated_at' => $this->updatedAt->toIso8601(),
        ];
    }

    /**
     * @param array<string, array<string, string>> $contacts
     * @param array<string, string>                $billingAddress
     * @param array<string, string>                $shippingAddress
     * @param array<string, mixed>                 $packageSelection
     * @param array<string, mixed>                 $totals
     */
    private static function hashFor(
        string $id,
        string $conversationId,
        ?string $journeyId,
        ActorScope $actor,
        string $cartHash,
        ?string $fulfillmentMode,
        array $contacts,
        array $billingAddress,
        array $shippingAddress,
        array $packageSelection,
        ?string $paymentMethodId,
        array $totals,
        string $status,
        int $version,
        UtcInstant $expiresAt
    ): StateHash {
        return StateHash::fromPayload([
            'checkout_session_id' => $id,
            'conversation_id' => $conversationId,
            'journey_id' => $journeyId,
            'actor_scope_hash' => $actor->hash(),
            'cart_hash' => $cartHash,
            'fulfillment_mode' => $fulfillmentMode,
            'contacts' => $contacts,
            'billing_address' => $billingAddress,
            'shipping_address' => $shippingAddress,
            'package_selection' => $packageSelection,
            'payment_method_id' => $paymentMethodId,
            'totals' => $totals,
            'status' => $status,
            'version' => $version,
            'expires_at' => $expiresAt->toIso8601(),
        ]);
    }

    private static function boundedLifetime(int $seconds): int
    {
        if ($seconds < 900 || $seconds > 2592000) {
            throw new \InvalidArgumentException('Checkout lifetime is outside the safe bound.');
        }

        return $seconds;
    }

    private static function assertIdentifier(string $value, string $kind): void
    {
        if ($value === '' || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException(sprintf('Checkout %s ID is invalid.', $kind));
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Expected a nullable string.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function decodeObject(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        if (!is_string($json)) {
            throw new \UnexpectedValueException('Checkout JSON column is invalid.');
        }
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new \UnexpectedValueException('Checkout JSON column must contain an object.');
        }

        return $decoded;
    }

    /** @param array<mixed> $value @return array<string, string> */
    private static function stringMap(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || !is_string($item)) {
                throw new \UnexpectedValueException('Checkout address values must be strings.');
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
