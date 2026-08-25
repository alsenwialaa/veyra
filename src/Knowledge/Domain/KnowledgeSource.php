<?php
declare(strict_types=1);

namespace Veyra\Knowledge\Domain;

// Internal validation exceptions are never rendered; adapters escape at the output boundary.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use Veyra\Shared\Domain\UtcInstant;

final class KnowledgeSource
{
    private const TYPES = [
        'policy', 'product_guide', 'shipping_policy', 'payment_policy',
        'return_policy', 'review_evidence', 'faq',
    ];

    private const AUTHORITIES = [
        'verified_review_evidence' => 10,
        'merchant_approved' => 20,
        'authoritative_product_guide' => 30,
        'authoritative_policy' => 40,
    ];

    /**
     * @param array<int, string> $markets
     * @param array<int, string> $branches
     * @param array<int, string> $keywords
     * @param array<int, int> $productIds
     * @param array<int, array<string, string>> $citations
     */
    private function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $version,
        public readonly string $title,
        public readonly string $content,
        public readonly string $language,
        public readonly string $owner,
        public readonly string $authority,
        public readonly string $scope,
        public readonly array $markets,
        public readonly array $branches,
        public readonly array $keywords,
        public readonly array $productIds,
        public readonly ?string $policyKey,
        public readonly array $citations,
        public readonly string $dataClassification,
        public readonly string $injectionTreatment,
        public readonly \DateTimeImmutable $effectiveFrom,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly string $approvedAt
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromPublishedRow(array $row): self
    {
        self::requireKeys($row, [
            'id', 'type', 'version', 'title', 'content', 'language', 'owner',
            'authority', 'scope', 'status', 'effective_from', 'approved_at',
            'citations', 'data_classification', 'injection_treatment',
        ]);

        $id = self::boundedString($row['id'], 1, 128, 'source id');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $id) !== 1) {
            throw new \InvalidArgumentException('Knowledge source id is invalid.');
        }
        $type = self::boundedString($row['type'], 1, 40, 'source type');
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Knowledge source type is unsupported.');
        }
        if ($row['status'] !== 'approved') {
            throw new \InvalidArgumentException('Knowledge source is not approved.');
        }
        $authority = self::boundedString($row['authority'], 1, 64, 'source authority');
        if (!isset(self::AUTHORITIES[$authority])) {
            throw new \InvalidArgumentException('Knowledge source authority is unsupported.');
        }
        $scope = self::boundedString($row['scope'], 1, 32, 'source scope');
        if (!in_array($scope, ['public', 'authenticated_customer', 'staff'], true)) {
            throw new \InvalidArgumentException('Knowledge source scope is unsupported.');
        }
        $classification = self::boundedString($row['data_classification'], 1, 32, 'data classification');
        if (!in_array($classification, ['public', 'customer'], true)) {
            throw new \InvalidArgumentException('Knowledge source data classification is unsafe.');
        }
        if ($classification === 'customer' && $scope === 'public') {
            throw new \InvalidArgumentException('Customer-classified knowledge cannot have public scope.');
        }
        $treatment = self::boundedString($row['injection_treatment'], 1, 32, 'injection treatment');
        if (!in_array($treatment, ['content_only', 'quarantined'], true)) {
            throw new \InvalidArgumentException('Knowledge injection treatment is unsupported.');
        }

        $effective = self::date($row['effective_from'], 'effective date');
        $expires = isset($row['expires_at']) && $row['expires_at'] !== ''
            ? self::date($row['expires_at'], 'expiry date')
            : null;
        if ($expires !== null && $expires <= $effective) {
            throw new \InvalidArgumentException('Knowledge source expiry must follow its effective date.');
        }

        $citations = self::citations($row['citations']);
        if ($citations === []) {
            throw new \InvalidArgumentException('Published knowledge requires a citation.');
        }

        return new self(
            $id,
            $type,
            self::boundedString($row['version'], 1, 64, 'source version'),
            self::boundedString($row['title'], 1, 500, 'source title'),
            self::boundedString($row['content'], 1, 120000, 'source content'),
            self::boundedString($row['language'], 1, 24, 'source language'),
            self::boundedString($row['owner'], 1, 200, 'source owner'),
            $authority,
            $scope,
            self::stringList($row['markets'] ?? [], 50, 64),
            self::stringList($row['branches'] ?? [], 100, 64),
            self::stringList($row['keywords'] ?? [], 100, 100),
            self::integerList($row['product_ids'] ?? [], 200),
            isset($row['policy_key']) && $row['policy_key'] !== ''
                ? self::boundedString($row['policy_key'], 1, 100, 'policy key')
                : null,
            $citations,
            $classification,
            $treatment,
            $effective,
            $expires,
            self::date($row['approved_at'], 'approval date')->format(DATE_ATOM)
        );
    }

    public function authorityRank(): int
    {
        return self::AUTHORITIES[$this->authority];
    }

    public function isFresh(UtcInstant $now): bool
    {
        $instant = new \DateTimeImmutable($now->toIso8601());
        return $this->injectionTreatment !== 'quarantined'
            && $instant >= $this->effectiveFrom
            && ($this->expiresAt === null || $instant < $this->expiresAt);
    }

    public function freshnessReason(UtcInstant $now): string
    {
        if ($this->injectionTreatment === 'quarantined') {
            return 'source_quarantined';
        }
        $instant = new \DateTimeImmutable($now->toIso8601());
        if ($instant < $this->effectiveFrom) {
            return 'source_not_yet_effective';
        }
        if ($this->expiresAt !== null && $instant >= $this->expiresAt) {
            return 'source_expired';
        }
        return 'fresh';
    }

    public function accessibleTo(string $actorType): bool
    {
        if ($this->dataClassification === 'customer' && $actorType === 'guest') {
            return false;
        }
        if ($this->scope === 'public') {
            return true;
        }
        if ($this->scope === 'authenticated_customer') {
            return in_array($actorType, ['customer', 'support', 'reviewer', 'manager', 'administrator'], true);
        }
        return in_array($actorType, ['support', 'reviewer', 'manager', 'administrator'], true);
    }

    public function matchesContext(string $locale, ?string $market, ?string $branch): bool
    {
        $language = strtolower(str_replace('_', '-', $this->language));
        $requested = strtolower(str_replace('_', '-', $locale));
        $languageMatches = $language === '*'
            || $language === $requested
            || explode('-', $language)[0] === explode('-', $requested)[0];
        if (!$languageMatches) {
            return false;
        }
        if ($this->markets !== [] && ($market === null || !in_array($market, $this->markets, true))) {
            return false;
        }
        if ($this->branches !== [] && ($branch === null || !in_array($branch, $this->branches, true))) {
            return false;
        }
        return true;
    }

    /** @return array<string, mixed> */
    public function metadata(UtcInstant $now): array
    {
        return [
            'source_id' => $this->id,
            'source_type' => $this->type,
            'version' => $this->version,
            'title' => $this->title,
            'language' => $this->language,
            'owner' => $this->owner,
            'authority' => $this->authority,
            'scope' => $this->scope,
            'markets' => $this->markets,
            'branches' => $this->branches,
            'product_ids' => $this->productIds,
            'policy_key' => $this->policyKey,
            'data_classification' => $this->dataClassification,
            'fresh' => $this->isFresh($now),
            'freshness_reason' => $this->freshnessReason($now),
            'effective_from' => $this->effectiveFrom->format(DATE_ATOM),
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'approved_at' => $this->approvedAt,
            'content_role' => 'untrusted_evidence',
            'embedded_instructions_authorized' => false,
        ];
    }

    /** @param array<string, mixed> $row @param array<int, string> $keys */
    private static function requireKeys(array $row, array $keys): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                throw new \InvalidArgumentException('Published knowledge source is incomplete.');
            }
        }
    }

    private static function boundedString(mixed $value, int $minimum, int $maximum, string $label): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException($label . ' must be a string.');
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length < $minimum || $length > $maximum) {
            throw new \InvalidArgumentException($label . ' is outside its allowed length.');
        }
        return $value;
    }

    private static function date(mixed $value, string $label): \DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException($label . ' is invalid.');
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            throw new \InvalidArgumentException($label . ' is invalid.');
        }
    }

    /** @return array<int, string> */
    private static function stringList(mixed $value, int $maximumItems, int $maximumLength): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximumItems) {
            throw new \InvalidArgumentException('Knowledge source list is invalid.');
        }
        $result = [];
        foreach ($value as $item) {
            $item = self::boundedString($item, 1, $maximumLength, 'knowledge list item');
            $result[] = $item;
        }
        return array_values(array_unique($result));
    }

    /** @return array<int, int> */
    private static function integerList(mixed $value, int $maximumItems): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximumItems) {
            throw new \InvalidArgumentException('Knowledge product id list is invalid.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_int($item) || $item < 1) {
                throw new \InvalidArgumentException('Knowledge product id is invalid.');
            }
            $result[] = $item;
        }
        return array_values(array_unique($result));
    }

    /** @return array<int, array<string, string>> */
    private static function citations(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 20) {
            throw new \InvalidArgumentException('Knowledge citations are invalid.');
        }
        $result = [];
        foreach ($value as $citation) {
            if (!is_array($citation)) {
                throw new \InvalidArgumentException('Knowledge citation is invalid.');
            }
            $item = [
                'citation_id' => self::boundedString($citation['citation_id'] ?? null, 1, 128, 'citation id'),
                'label' => self::boundedString($citation['label'] ?? null, 1, 500, 'citation label'),
            ];
            if (isset($citation['locator']) && $citation['locator'] !== '') {
                $item['locator'] = self::boundedString($citation['locator'], 1, 1000, 'citation locator');
            }
            if (isset($citation['url']) && $citation['url'] !== '') {
                $url = self::boundedString($citation['url'], 1, 2000, 'citation URL');
                $scheme = function_exists('wp_parse_url')
                    ? wp_parse_url($url, PHP_URL_SCHEME)
                    : (preg_match('/\A([A-Za-z][A-Za-z0-9+.-]*):/', $url, $matches) === 1 ? $matches[1] : null);
                $scheme = is_string($scheme) ? strtolower($scheme) : '';
                if (!in_array($scheme, ['http', 'https'], true)) {
                    throw new \InvalidArgumentException('Knowledge citation URL scheme is invalid.');
                }
                $item['url'] = $url;
            }
            $result[] = $item;
        }
        return $result;
    }
}
