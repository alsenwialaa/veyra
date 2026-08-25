<?php
declare(strict_types=1);

namespace Veyra\Recommendation\Tool;

/** Closed data contracts for successful and stale recommendation results. */
final class RecommendationOutputSchemas
{
    /** @return array<string, mixed> */
    public static function for(string $toolName): array
    {
        $successful = match ($toolName) {
            'recommendation.retrieve_candidates' => self::retrieve(),
            'recommendation.apply_hard_filters' => self::hardFilters(),
            'recommendation.rank' => self::rank(),
            'recommendation.diversify' => self::diversify(),
            'recommendation.explain' => self::explain(),
            default => [],
        };
        if ($successful === [] || $toolName === 'recommendation.retrieve_candidates') {
            return $successful;
        }

        return ['oneOf' => [$successful, self::stale()]];
    }

    /** @return array<string, mixed> */
    private static function retrieve(): array
    {
        return self::object([
            'candidate_source', 'candidates', 'unavailable_product_ids', 'complete',
            'selection_performed', 'selection_required', 'exact_configuration_required',
            'exact_commerce_action_ready',
        ], [
            'candidate_source' => ['const' => 'explicit_product_ids'],
            'candidates' => self::list(self::candidate()),
            'unavailable_product_ids' => self::idList(),
            'complete' => self::boolean(),
            'selection_performed' => ['const' => false],
            'selection_required' => self::boolean(),
            'exact_configuration_required' => self::boolean(),
            'exact_commerce_action_ready' => self::boolean(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function hardFilters(): array
    {
        return self::bound(self::object([
            'eligible_candidates', 'rejected_candidates', 'unavailable_product_ids',
            'unresolved_hard_requirements', 'complete', 'hard_filter_authority',
            'compatibility_assumed',
        ], [
            'eligible_candidates' => self::list(self::candidate()),
            'rejected_candidates' => self::list(self::rejectedCandidate()),
            'unavailable_product_ids' => self::idList(),
            'unresolved_hard_requirements' => self::list(self::unresolvedRequirement(), 64),
            'complete' => self::boolean(),
            'hard_filter_authority' => ['const' => 'woocommerce_runtime_and_explicit_structured_requirements'],
            'compatibility_assumed' => ['const' => false],
        ]));
    }

    /** @return array<string, mixed> */
    private static function rank(): array
    {
        return self::bound(self::object([
            'ranked_candidates', 'rejected_candidates', 'unavailable_product_ids',
            'unresolved_hard_requirements', 'unscored_soft_requirements', 'tie_groups',
            'singular_winner', 'selection_required', 'top_exact_configuration_required',
            'exact_commerce_action_ready', 'ranking_basis',
            'semantic_free_text_scoring_used', 'policy',
        ], [
            'ranked_candidates' => self::list(self::rankedCandidate()),
            'rejected_candidates' => self::list(self::rejectedCandidate()),
            'unavailable_product_ids' => self::idList(),
            'unresolved_hard_requirements' => self::list(self::unresolvedRequirement(), 64),
            'unscored_soft_requirements' => self::requirementIdList(),
            'tie_groups' => self::list(self::tieGroup()),
            'singular_winner' => self::boolean(),
            'selection_required' => self::boolean(),
            'top_exact_configuration_required' => self::boolean(),
            'exact_commerce_action_ready' => self::boolean(),
            'ranking_basis' => ['const' => 'current_woocommerce_facts_and_published_structured_weights'],
            'semantic_free_text_scoring_used' => ['const' => false],
            'policy' => self::policy(),
        ]));
    }

    /** @return array<string, mixed> */
    private static function diversify(): array
    {
        return self::bound(self::object([
            'diversified_candidates', 'top_tie_product_ids', 'singular_winner',
            'selection_required', 'unavailable_product_ids', 'algorithm',
            'first_result_shortcut_used', 'rejected_candidates',
            'unresolved_hard_requirements', 'unscored_soft_requirements', 'tie_groups',
            'ranking_basis', 'semantic_free_text_scoring_used', 'policy',
            'scores_supplied_by_caller',
        ], [
            'diversified_candidates' => self::list(self::diversifiedCandidate(), 8),
            'top_tie_product_ids' => self::idList(),
            'singular_winner' => self::boolean(),
            'selection_required' => ['const' => true],
            'unavailable_product_ids' => self::idList(),
            'algorithm' => ['const' => 'score_preserving_category_type_price_diversity'],
            'first_result_shortcut_used' => ['const' => false],
            'rejected_candidates' => self::list(self::rejectedCandidate()),
            'unresolved_hard_requirements' => self::list(self::unresolvedRequirement(), 64),
            'unscored_soft_requirements' => self::requirementIdList(),
            'tie_groups' => self::list(self::tieGroup()),
            'ranking_basis' => ['const' => 'current_woocommerce_facts_and_published_structured_weights'],
            'semantic_free_text_scoring_used' => ['const' => false],
            'policy' => self::policy(),
            'scores_supplied_by_caller' => ['const' => false],
        ]));
    }

    /** @return array<string, mixed> */
    private static function explain(): array
    {
        return self::bound(self::object([
            'explanations', 'unavailable_product_ids', 'complete', 'evidence_grounded',
        ], [
            'explanations' => self::list(self::explanation()),
            'unavailable_product_ids' => self::idList(),
            'complete' => self::boolean(),
            'evidence_grounded' => ['const' => true],
        ]));
    }

    /** @param array<string, mixed> $schema @return array<string, mixed> */
    private static function bound(array $schema): array
    {
        $schema['required'][] = 'bound_requirements_state';
        $schema['properties']['bound_requirements_state'] = self::object([
            'scope', 'resource_version', 'state_hash', 'active_requirement_count',
        ], [
            'scope' => ['const' => 'current_conversation_only'],
            'resource_version' => ['type' => 'integer', 'minimum' => 0],
            'state_hash' => self::stateHash(),
            'active_requirement_count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 64],
        ]);
        return $schema;
    }

    /** @return array<string, mixed> */
    private static function stale(): array
    {
        return self::object(['requirements_refresh_required', 'scope'], [
            'requirements_refresh_required' => ['const' => true],
            'scope' => ['const' => 'current_conversation_only'],
            'current_requirements_resource_version' => ['type' => 'integer', 'minimum' => 0],
            'current_requirements_state_hash' => self::stateHash(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function candidate(): array
    {
        return self::object([
            'product_id', 'parent_id', 'name', 'sku', 'product_type', 'visible',
            'purchasable', 'in_stock', 'backorders_allowed', 'unit_price', 'currency',
            'stock_status', 'categories', 'attributes', 'permalink', 'image_id',
            'observed_at', 'evidence', 'compatibility_verified',
            'exact_configuration_required', 'minimum_quantity', 'maximum_quantity',
            'stock_quantity', 'manages_stock', 'sold_individually',
        ], [
            'product_id' => self::positiveInteger(),
            'parent_id' => ['type' => 'integer', 'minimum' => 0],
            'name' => self::string(500, 1),
            'sku' => self::string(200),
            'product_type' => self::string(80, 1),
            'visible' => self::boolean(),
            'purchasable' => self::boolean(),
            'in_stock' => self::boolean(),
            'backorders_allowed' => self::boolean(),
            'unit_price' => ['type' => ['number', 'null'], 'minimum' => 0],
            'currency' => self::string(12, 1),
            'stock_status' => self::string(40, 1),
            'categories' => self::list(self::string(200, 1), 100),
            'attributes' => self::list(self::object(['name', 'values'], [
                'name' => self::string(200, 1),
                'values' => self::list(self::string(200, 1), 100),
            ]), 100),
            'permalink' => self::string(2048),
            'image_id' => ['type' => 'integer', 'minimum' => 0],
            'observed_at' => self::timestamp(),
            'minimum_quantity' => ['type' => 'number', 'minimum' => 0.000001],
            'maximum_quantity' => ['type' => ['number', 'null']],
            'stock_quantity' => ['type' => ['number', 'null']],
            'manages_stock' => self::boolean(),
            'sold_individually' => self::boolean(),
            'evidence' => self::object(['source', 'product_id', 'observed_at'], [
                'source' => ['const' => 'woocommerce_runtime'],
                'product_id' => self::positiveInteger(),
                'observed_at' => self::timestamp(),
            ]),
            'compatibility_verified' => ['const' => false],
            'exact_configuration_required' => self::boolean(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function rejectedCandidate(): array
    {
        return self::object(['product_id', 'reasons'], [
            'product_id' => self::positiveInteger(),
            'reasons' => self::list(self::assessment(false), 70),
        ]);
    }

    /** @return array<string, mixed> */
    private static function unresolvedRequirement(): array
    {
        return self::object(['requirement_id', 'field', 'code'], [
            'requirement_id' => self::requirementId(),
            'field' => self::string(80, 1),
            'code' => self::code(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function rankedCandidate(): array
    {
        return self::object(['product_id', 'score', 'score_components', 'candidate'], [
            'product_id' => self::positiveInteger(),
            'score' => self::score(),
            'score_components' => self::list(self::scoreComponent(), 65),
            'candidate' => self::candidate(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function scoreComponent(): array
    {
        return self::object(['field', 'weight', 'status', 'evidence'], [
            'requirement_id' => self::requirementId(),
            'field' => self::string(80, 1),
            'weight' => ['type' => 'number', 'minimum' => 0, 'maximum' => 10],
            'status' => ['enum' => ['met', 'mismatch', 'unknown']],
            'code' => self::code(),
            'evidence' => self::assessmentEvidence(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function diversifiedCandidate(): array
    {
        return self::object([
            'product_id', 'score', 'diversity_penalty', 'diversified_score',
            'penalty_reasons',
        ], [
            'product_id' => self::positiveInteger(),
            'score' => self::score(),
            'diversity_penalty' => ['type' => 'number', 'minimum' => 0],
            'diversified_score' => ['type' => 'number'],
            'penalty_reasons' => self::list(self::string(80, 1), 40),
        ]);
    }

    /** @return array<string, mixed> */
    private static function tieGroup(): array
    {
        return self::object(['score', 'product_ids'], [
            'score' => self::score(),
            'product_ids' => self::idList(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function explanation(): array
    {
        return self::object([
            'product_id', 'classification', 'requirement_assessments',
            'meaningful_tradeoffs', 'current_product_evidence',
            'compatibility_claim_made', 'natural_language_explanation_generated',
        ], [
            'product_id' => self::positiveInteger(),
            'classification' => [
                'enum' => [
                    'not_eligible', 'not_verified', 'close_structured_alternative',
                    'structured_exact_fit',
                ],
            ],
            'requirement_assessments' => self::list(self::assessment(true), 64),
            'meaningful_tradeoffs' => self::list(self::code(), 64),
            'current_product_evidence' => self::candidate(),
            'compatibility_claim_made' => ['const' => false],
            'natural_language_explanation_generated' => ['const' => false],
        ]);
    }

    /** @return array<string, mixed> */
    private static function assessment(bool $requireIdentity): array
    {
        $required = ['status', 'code', 'evidence'];
        if ($requireIdentity) {
            array_unshift($required, 'requirement_id', 'field', 'strength');
        }
        return self::object($required, [
            'requirement_id' => self::requirementId(),
            'field' => self::string(80, 1),
            'strength' => ['enum' => ['hard', 'soft']],
            'status' => ['enum' => ['met', 'mismatch', 'unknown']],
            'code' => self::code(),
            'evidence' => self::assessmentEvidence(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function assessmentEvidence(): array
    {
        return self::object([], [
            'source' => ['const' => 'woocommerce_runtime'],
            'product_id' => self::positiveInteger(),
            'observed_at' => self::timestamp(),
            'visible' => self::boolean(),
            'purchasable' => self::boolean(),
            'in_stock' => self::boolean(),
            'backorders_allowed' => self::boolean(),
            'unit_price' => ['type' => ['number', 'null'], 'minimum' => 0],
            'currency' => self::string(12, 1),
            'observed_amount' => ['type' => 'number', 'minimum' => 0],
            'quantity' => ['type' => 'number'],
            'observed_product_type' => self::string(80, 1),
            'observed_categories' => self::list(self::string(200, 1), 100),
            'attribute' => self::string(200, 1),
            'observed_values' => self::list(self::string(200, 1), 100),
            'observed_product_id' => self::positiveInteger(),
            'compatibility_verified' => self::boolean(),
            'minimum_quantity' => ['type' => 'number', 'minimum' => 0.000001],
            'maximum_quantity' => ['type' => ['number', 'null']],
            'stock_quantity' => ['type' => ['number', 'null']],
            'manages_stock' => self::boolean(),
            'sold_individually' => self::boolean(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function policy(): array
    {
        $weightNames = [
            'availability', 'budget', 'quantity', 'product_type', 'category',
            'attribute', 'product_id', 'stock', 'purchasable', 'exclusion',
            'already_owned', 'unit', 'use_case', 'recipient', 'compatibility',
            'location', 'timing', 'preference',
        ];
        $weights = [];
        foreach ($weightNames as $name) {
            $weights[$name] = ['type' => 'number', 'minimum' => 0, 'maximum' => 10];
        }
        return self::object([
            'publication_id', 'version', 'store_id', 'published_at', 'weights',
        ], [
            'publication_id' => self::string(191, 1),
            'version' => self::string(80, 1),
            'store_id' => self::positiveInteger(),
            'published_at' => self::timestamp(),
            'weights' => self::object($weightNames, $weights),
        ]);
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
    private static function list(array $items, int $maximum = 40): array
    {
        return ['type' => 'array', 'maxItems' => $maximum, 'items' => $items];
    }

    /** @return array<string, mixed> */
    private static function idList(): array
    {
        return ['type' => 'array', 'maxItems' => 40, 'uniqueItems' => true, 'items' => self::positiveInteger()];
    }

    /** @return array<string, mixed> */
    private static function requirementIdList(): array
    {
        return ['type' => 'array', 'maxItems' => 64, 'uniqueItems' => true, 'items' => self::requirementId()];
    }

    /** @return array<string, mixed> */
    private static function requirementId(): array
    {
        return ['type' => 'string', 'pattern' => '^req_[a-f0-9]{32}$'];
    }

    /** @return array<string, mixed> */
    private static function stateHash(): array
    {
        return ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];
    }

    /** @return array<string, mixed> */
    private static function positiveInteger(): array
    {
        return ['type' => 'integer', 'minimum' => 1];
    }

    /** @return array<string, mixed> */
    private static function score(): array
    {
        return ['type' => 'number', 'minimum' => 0, 'maximum' => 100];
    }

    /** @return array<string, mixed> */
    private static function boolean(): array
    {
        return ['type' => 'boolean'];
    }

    /** @return array<string, mixed> */
    private static function string(int $maximum, int $minimum = 0): array
    {
        return ['type' => 'string', 'minLength' => $minimum, 'maxLength' => $maximum];
    }

    /** @return array<string, mixed> */
    private static function code(): array
    {
        return ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,119}$'];
    }

    /** @return array<string, mixed> */
    private static function timestamp(): array
    {
        return ['type' => 'string', 'minLength' => 20, 'maxLength' => 40];
    }
}
