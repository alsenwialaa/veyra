<?php
declare(strict_types=1);

namespace Veyra\Knowledge\Tool;

/** Exact provider-facing contracts for successful governed-knowledge results. */
final class KnowledgeOutputSchemas
{
    /** @return array<string, mixed> */
    public static function for(string $toolName): array
    {
        return match ($toolName) {
            'knowledge.search' => self::search(),
            'knowledge.read_source' => self::readSource(),
            'knowledge.get_policy',
            'knowledge.get_product_guide',
            'knowledge.get_shipping_policy',
            'knowledge.get_payment_policy',
            'knowledge.get_return_policy' => self::policy(),
            'knowledge.check_freshness' => self::freshness(),
            'knowledge.resolve_conflict' => self::conflict(),
            'knowledge.get_citations' => self::citationsResult(),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private static function search(): array
    {
        return self::object([
            'available', 'query', 'count', 'total_matching', 'results',
            'selection_required', 'selection_performed', 'truncated', 'complete',
            'retrieval', 'publication',
        ], [
            'available' => ['const' => true],
            'query' => self::string(2000, 1),
            'count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 12],
            'total_matching' => ['type' => 'integer', 'minimum' => 0],
            'results' => self::list(self::searchResult(), 12),
            'selection_required' => self::boolean(),
            'selection_performed' => ['const' => false],
            'truncated' => self::boolean(),
            'complete' => self::boolean(),
            'retrieval' => ['const' => 'bounded_published_index'],
            'publication' => self::publication(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function readSource(): array
    {
        return self::object(['available', 'source', 'publication'], [
            'available' => ['const' => true],
            'source' => self::sourceWithContent(24000, true),
            'publication' => self::publication(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function policy(): array
    {
        return self::object([
            'available', 'sources', 'count', 'total_matching',
            'selection_required', 'selection_performed',
            'conflict_check_required', 'truncated', 'complete', 'publication',
        ], [
            'available' => ['const' => true],
            'sources' => self::list(self::sourceWithContent(20000, false), 5),
            'count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            'total_matching' => ['type' => 'integer', 'minimum' => 1],
            'selection_required' => self::boolean(),
            'selection_performed' => ['const' => false],
            'conflict_check_required' => self::boolean(),
            'truncated' => self::boolean(),
            'complete' => self::boolean(),
            'publication' => self::publication(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function freshness(): array
    {
        $unavailable = self::object(['source_id', 'available', 'fresh', 'reason'], [
            'source_id' => self::sourceId(),
            'available' => ['const' => false],
            'fresh' => ['const' => false],
            'reason' => ['const' => 'source_unavailable'],
        ]);
        $available = self::metadata();
        $available['required'][] = 'available';
        $available['properties']['available'] = ['const' => true];

        return self::object(['available', 'results', 'all_fresh', 'publication'], [
            'available' => ['const' => true],
            'results' => self::list(['oneOf' => [$unavailable, $available]], 20),
            'all_fresh' => self::boolean(),
            'publication' => self::publication(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function conflict(): array
    {
        return self::object([
            'available', 'resolution_status', 'selected_source_id', 'basis',
            'precedence', 'content_interpretation_performed', 'publication',
        ], [
            'available' => ['const' => true],
            'resolution_status' => ['enum' => ['unresolved', 'resolved_by_published_precedence']],
            'selected_source_id' => ['type' => ['string', 'null'], 'minLength' => 1, 'maxLength' => 128],
            'basis' => ['enum' => [
                'merchant_reconciliation_required',
                'only_fresh_source',
                'unique_higher_published_authority',
            ]],
            'precedence' => self::list(self::metadata(), 10),
            'content_interpretation_performed' => ['const' => false],
            'publication' => self::publication(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function citationsResult(): array
    {
        return self::object([
            'available', 'sources', 'missing_source_ids', 'complete', 'publication',
        ], [
            'available' => ['const' => true],
            'sources' => self::list(self::citationSource(), 20),
            'missing_source_ids' => self::list(self::sourceId(), 20),
            'complete' => self::boolean(),
            'publication' => self::publication(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function searchResult(): array
    {
        $schema = self::metadata();
        array_push($schema['required'], 'retrieval_score', 'snippet', 'citations');
        $schema['properties']['retrieval_score'] = ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000];
        $schema['properties']['snippet'] = self::string(20000, 1);
        $schema['properties']['citations'] = self::citations();
        return $schema;
    }

    /** @return array<string, mixed> */
    private static function sourceWithContent(int $maximumBytes, bool $chunked): array
    {
        $schema = self::metadata();
        array_push($schema['required'], 'content', 'truncated', 'citations');
        $schema['properties']['content'] = self::string($maximumBytes, 1);
        $schema['properties']['truncated'] = self::boolean();
        $schema['properties']['citations'] = self::citations();
        if ($chunked) {
            array_push(
                $schema['required'],
                'content_offset',
                'content_length',
                'source_content_length'
            );
            $schema['properties']['content_offset'] = ['type' => 'integer', 'minimum' => 0, 'maximum' => 120000];
            $schema['properties']['content_length'] = ['type' => 'integer', 'minimum' => 1, 'maximum' => 6000];
            $schema['properties']['source_content_length'] = ['type' => 'integer', 'minimum' => 1, 'maximum' => 120000];
        }
        return $schema;
    }

    /** @return array<string, mixed> */
    private static function metadata(): array
    {
        return self::object([
            'source_id', 'source_type', 'version', 'title', 'language', 'owner',
            'authority', 'scope', 'markets', 'branches', 'product_ids', 'policy_key',
            'data_classification', 'fresh', 'freshness_reason', 'effective_from',
            'expires_at', 'approved_at', 'content_role',
            'embedded_instructions_authorized',
        ], [
            'source_id' => self::sourceId(),
            'source_type' => ['enum' => [
                'policy', 'product_guide', 'shipping_policy', 'payment_policy',
                'return_policy', 'review_evidence', 'faq',
            ]],
            'version' => self::string(256, 1),
            'title' => self::string(4000, 1),
            'language' => self::string(96, 1),
            'owner' => self::string(800, 1),
            'authority' => ['enum' => [
                'verified_review_evidence', 'merchant_approved',
                'authoritative_product_guide', 'authoritative_policy',
            ]],
            'scope' => ['enum' => ['public', 'authenticated_customer', 'staff']],
            'markets' => self::list(self::string(256, 1), 50),
            'branches' => self::list(self::string(256, 1), 100),
            'product_ids' => self::list(['type' => 'integer', 'minimum' => 1], 200),
            'policy_key' => ['type' => ['string', 'null'], 'minLength' => 1, 'maxLength' => 400],
            'data_classification' => ['enum' => ['public', 'customer']],
            'fresh' => self::boolean(),
            'freshness_reason' => ['enum' => [
                'fresh', 'source_quarantined', 'source_not_yet_effective', 'source_expired',
            ]],
            'effective_from' => self::timestamp(),
            'expires_at' => ['type' => ['string', 'null'], 'maxLength' => 80],
            'approved_at' => self::timestamp(),
            'content_role' => ['const' => 'untrusted_evidence'],
            'embedded_instructions_authorized' => ['const' => false],
        ]);
    }

    /** @return array<string, mixed> */
    private static function citationSource(): array
    {
        return self::object([
            'source_id', 'source_version', 'fresh', 'freshness_reason', 'citations',
        ], [
            'source_id' => self::sourceId(),
            'source_version' => self::string(256, 1),
            'fresh' => self::boolean(),
            'freshness_reason' => ['enum' => [
                'fresh', 'source_quarantined', 'source_not_yet_effective', 'source_expired',
            ]],
            'citations' => self::citations(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function citations(): array
    {
        return self::list(self::object(['citation_id', 'label'], [
            'citation_id' => self::string(512, 1),
            'label' => self::string(4000, 1),
            'locator' => self::string(4000, 1),
            'url' => self::string(8000, 1),
        ]), 20, 1);
    }

    /** @return array<string, mixed> */
    private static function publication(): array
    {
        return self::object(['publication_id', 'publication_version', 'store_id', 'published_at'], [
            'publication_id' => self::string(512, 1),
            'publication_version' => self::string(512, 1),
            'store_id' => ['type' => 'integer', 'minimum' => 1],
            'published_at' => self::timestamp(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function sourceId(): array
    {
        return [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 128,
            'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]*$',
        ];
    }

    /** @param list<string> $required @param array<string, mixed> $properties @return array<string, mixed> */
    private static function object(array $required, array $properties): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $required,
            'properties' => $properties,
        ];
    }

    /** @param array<string, mixed> $items @return array<string, mixed> */
    private static function list(array $items, int $maximum, int $minimum = 0): array
    {
        return [
            'type' => 'array',
            'minItems' => $minimum,
            'maxItems' => $maximum,
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private static function string(int $maximum, int $minimum = 0): array
    {
        return ['type' => 'string', 'minLength' => $minimum, 'maxLength' => $maximum];
    }

    /** @return array<string, mixed> */
    private static function timestamp(): array
    {
        return self::string(80, 1);
    }

    /** @return array<string, mixed> */
    private static function boolean(): array
    {
        return ['type' => 'boolean'];
    }
}
