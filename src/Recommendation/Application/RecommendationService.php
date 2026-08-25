<?php
declare(strict_types=1);

namespace Veyra\Recommendation\Application;

use Veyra\Recommendation\Contract\ProductCandidateRepository;
use Veyra\Recommendation\Contract\PublishedRecommendationPolicyRepository;
use Veyra\Recommendation\Domain\ProductCandidate;
use Veyra\Recommendation\Domain\ProductCandidateSet;
use Veyra\Recommendation\Domain\RecommendationPolicy;
use Veyra\Requirements\Domain\RequirementCriterion;

final class RecommendationService
{
    private const MAXIMUM_CANDIDATES = 40;

    public function __construct(
        private readonly ProductCandidateRepository $products,
        private readonly PublishedRecommendationPolicyRepository $policies
    ) {
    }

    /** @param array<int, mixed> $productIds @return array<string, mixed> */
    public function retrieve(array $productIds): array
    {
        $ids = $this->candidateIds($productIds);
        if ($ids === null) {
            return ['ok' => false, 'code' => 'recommendation_candidate_ids_invalid'];
        }
        $set = $this->products->retrieve($ids);
        if (!$set->commerceAvailable) {
            return ['ok' => false, 'code' => 'woocommerce_unavailable'];
        }
        $exactConfigurationRequired = count($set->candidates) === 1
            && $this->requiresExactConfiguration($set->candidates[0]);
        return [
            'ok' => true,
            'candidate_source' => 'explicit_product_ids',
            'candidates' => array_map(static fn (ProductCandidate $candidate): array => $candidate->toArray(), $set->candidates),
            'unavailable_product_ids' => $set->unavailableIds,
            'complete' => $set->unavailableIds === [],
            'selection_performed' => false,
            'selection_required' => count($set->candidates) !== 1
                || $set->unavailableIds !== []
                || $exactConfigurationRequired,
            'exact_configuration_required' => $exactConfigurationRequired,
            'exact_commerce_action_ready' => count($set->candidates) === 1
                && $set->unavailableIds === []
                && !$exactConfigurationRequired,
        ];
    }

    /** @param array<int, mixed> $productIds @param array<int, mixed> $requirements @return array<string, mixed> */
    public function hardFilters(array $productIds, array $requirements): array
    {
        $ids = $this->candidateIds($productIds);
        $criteria = $this->requirements($requirements);
        if ($ids === null) {
            return ['ok' => false, 'code' => 'recommendation_candidate_ids_invalid'];
        }
        if ($criteria === null || $criteria === []) {
            return ['ok' => false, 'code' => 'recommendation_requirements_invalid'];
        }
        $set = $this->products->retrieve($ids);
        if (!$set->commerceAvailable) {
            return ['ok' => false, 'code' => 'woocommerce_unavailable'];
        }
        return array_merge(['ok' => true], $this->filterSet($set, $criteria));
    }

    /** @param array<int, mixed> $productIds @param array<int, mixed> $requirements @return array<string, mixed> */
    public function rank(array $productIds, array $requirements): array
    {
        $ids = $this->candidateIds($productIds);
        $criteria = $this->requirements($requirements);
        if ($ids === null) {
            return ['ok' => false, 'code' => 'recommendation_candidate_ids_invalid'];
        }
        if ($criteria === null || $criteria === []) {
            return ['ok' => false, 'code' => 'recommendation_requirements_invalid'];
        }
        $policy = $this->policies->published();
        if (!$policy instanceof RecommendationPolicy) {
            return ['ok' => false, 'code' => 'recommendation_policy_unavailable'];
        }
        $set = $this->products->retrieve($ids);
        if (!$set->commerceAvailable) {
            return ['ok' => false, 'code' => 'woocommerce_unavailable'];
        }
        $filtered = $this->filterSet($set, $criteria);
        $eligibleIds = array_map(static fn (array $item): int => (int) $item['product_id'], $filtered['eligible_candidates']);
        $soft = array_values(array_filter(
            $criteria,
            static fn (RequirementCriterion $criterion): bool => $criterion->status === 'active' && $criterion->strength === 'soft'
        ));
        $ranked = [];
        foreach ($set->candidates as $candidate) {
            if (!in_array($candidate->productId, $eligibleIds, true)) {
                continue;
            }
            $earned = $policy->weight('availability');
            $possible = $policy->weight('availability');
            $components = [[
                'field' => 'availability',
                'weight' => $policy->weight('availability'),
                'status' => 'met',
                'evidence' => $this->commerceEvidence($candidate),
            ]];
            foreach ($soft as $criterion) {
                $assessment = $this->assess($candidate, $criterion, $criteria);
                $weight = $policy->weight($criterion->field);
                // Unknown evidence is not a negative shopper signal. Keep it
                // explicitly unscored rather than silently lowering the fit.
                if ($assessment['status'] !== 'unknown') {
                    $possible += $weight;
                }
                if ($assessment['status'] === 'met') {
                    $earned += $weight;
                }
                $components[] = array_merge([
                    'requirement_id' => $criterion->id,
                    'field' => $criterion->field,
                    'weight' => $weight,
                ], $assessment);
            }
            $score = $possible > 0 ? round(($earned / $possible) * 100, 3) : 0.0;
            $ranked[] = [
                'product_id' => $candidate->productId,
                'score' => $score,
                'score_components' => $components,
                'candidate' => $candidate->toArray(),
            ];
        }
        usort($ranked, static function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];
            return $score !== 0 ? $score : $left['product_id'] <=> $right['product_id'];
        });
        $ties = $this->tieGroups($ranked);
        $topCandidate = $ranked[0]['candidate'] ?? null;
        $topRequiresConfiguration = is_array($topCandidate)
            && ($topCandidate['exact_configuration_required'] ?? false) === true;
        return [
            'ok' => true,
            'ranked_candidates' => $ranked,
            'rejected_candidates' => $filtered['rejected_candidates'],
            'unavailable_product_ids' => $set->unavailableIds,
            'unresolved_hard_requirements' => $filtered['unresolved_hard_requirements'],
            'unscored_soft_requirements' => $this->unscoredSoft($ranked),
            'tie_groups' => $ties,
            'singular_winner' => count($ranked) === 1 || ($ranked !== [] && ($ties[0]['product_ids'] ?? []) === [$ranked[0]['product_id']]),
            // Ranking may identify a best candidate, but it never silently
            // converts a multi-candidate set or variable parent into an exact
            // commerce target.
            'selection_required' => count($ranked) !== 1
                || $set->unavailableIds !== []
                || $topRequiresConfiguration,
            'top_exact_configuration_required' => $topRequiresConfiguration,
            'exact_commerce_action_ready' => count($ranked) === 1
                && $set->unavailableIds === []
                && !$topRequiresConfiguration,
            'ranking_basis' => 'current_woocommerce_facts_and_published_structured_weights',
            'semantic_free_text_scoring_used' => false,
            'policy' => $policy->metadata(),
        ];
    }

    /**
     * Rank from server-owned requirements before applying diversity. A tool
     * caller can supply candidate ids, but can never supply or forge scores.
     *
     * @param array<int, mixed> $productIds
     * @param array<int, mixed> $requirements
     * @return array<string, mixed>
     */
    public function rankAndDiversify(array $productIds, array $requirements, int $limit): array
    {
        $ranked = $this->rank($productIds, $requirements);
        if (($ranked['ok'] ?? false) !== true) {
            return $ranked;
        }

        $diversified = $this->diversifyRanked(
            is_array($ranked['ranked_candidates'] ?? null) ? $ranked['ranked_candidates'] : [],
            $limit
        );
        if (($diversified['ok'] ?? false) !== true) {
            return $diversified;
        }

        return array_merge($diversified, [
            'rejected_candidates' => $ranked['rejected_candidates'],
            'unavailable_product_ids' => $ranked['unavailable_product_ids'],
            'unresolved_hard_requirements' => $ranked['unresolved_hard_requirements'],
            'unscored_soft_requirements' => $ranked['unscored_soft_requirements'],
            'tie_groups' => $ranked['tie_groups'],
            'ranking_basis' => $ranked['ranking_basis'],
            'semantic_free_text_scoring_used' => false,
            'policy' => $ranked['policy'],
            'scores_supplied_by_caller' => false,
        ]);
    }

    /** @param array<int, mixed> $rankedCandidates @return array<string, mixed> */
    private function diversifyRanked(array $rankedCandidates, int $limit): array
    {
        $validated = $this->serverRankedInput($rankedCandidates);
        if ($validated === null || count($validated) < 2) {
            return ['ok' => false, 'code' => 'recommendation_ranked_candidates_invalid'];
        }
        $limit = max(2, min(8, $limit));
        $available = $validated;
        $candidateById = [];
        foreach ($available as $item) {
            $candidateById[$item['product_id']] = $item['candidate'];
        }
        usort($available, static function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];
            return $score !== 0 ? $score : $left['product_id'] <=> $right['product_id'];
        });
        if ($available === []) {
            return ['ok' => false, 'code' => 'recommendation_candidates_unavailable'];
        }
        $topScore = $available[0]['score'];
        $topTies = array_values(array_map(
            static fn (array $item): int => $item['product_id'],
            array_filter($available, static fn (array $item): bool => abs($item['score'] - $topScore) < 0.0005)
        ));
        $chosen = [];
        $remaining = $available;
        while ($remaining !== [] && count($chosen) < $limit) {
            $evaluated = [];
            foreach ($remaining as $item) {
                $candidate = $candidateById[$item['product_id']];
                $penalty = 0.0;
                $penaltyReasons = [];
                foreach ($chosen as $selected) {
                    $selectedCandidate = $candidateById[$selected['product_id']];
                    if ($candidate['product_type'] === $selectedCandidate['product_type']) {
                        $penalty += 5.0;
                        $penaltyReasons[] = 'same_product_type';
                    }
                    if (array_intersect($candidate['categories'], $selectedCandidate['categories']) !== []) {
                        $penalty += 8.0;
                        $penaltyReasons[] = 'shared_category';
                    }
                    if ($this->priceBand($candidate['unit_price']) === $this->priceBand($selectedCandidate['unit_price'])) {
                        $penalty += 4.0;
                        $penaltyReasons[] = 'same_price_band';
                    }
                }
                $evaluated[] = [
                    'product_id' => $item['product_id'],
                    'score' => $item['score'],
                    'diversity_penalty' => $penalty,
                    'diversified_score' => round($item['score'] - $penalty, 3),
                    'penalty_reasons' => array_values(array_unique($penaltyReasons)),
                ];
            }
            usort($evaluated, static function (array $left, array $right): int {
                $score = $right['diversified_score'] <=> $left['diversified_score'];
                return $score !== 0 ? $score : $left['product_id'] <=> $right['product_id'];
            });
            if ($evaluated === []) {
                break;
            }
            $next = $evaluated[0];
            $chosen[] = $next;
            $remaining = array_values(array_filter(
                $remaining,
                static fn (array $item): bool => $item['product_id'] !== $next['product_id']
            ));
        }
        return [
            'ok' => true,
            'diversified_candidates' => $chosen,
            'top_tie_product_ids' => $topTies,
            'singular_winner' => count($topTies) === 1,
            'selection_required' => true,
            'unavailable_product_ids' => [],
            'algorithm' => 'score_preserving_category_type_price_diversity',
            'first_result_shortcut_used' => false,
        ];
    }

    /** @param array<int, mixed> $productIds @param array<int, mixed> $requirements @return array<string, mixed> */
    public function explain(array $productIds, array $requirements): array
    {
        $ids = $this->candidateIds($productIds);
        $criteria = $this->requirements($requirements);
        if ($ids === null) {
            return ['ok' => false, 'code' => 'recommendation_candidate_ids_invalid'];
        }
        if ($criteria === null || $criteria === []) {
            return ['ok' => false, 'code' => 'recommendation_requirements_invalid'];
        }
        $set = $this->products->retrieve($ids);
        if (!$set->commerceAvailable) {
            return ['ok' => false, 'code' => 'woocommerce_unavailable'];
        }
        $explanations = [];
        foreach ($set->candidates as $candidate) {
            $assessments = [];
            $hardMismatch = false;
            $hardUnknown = false;
            $softMismatch = false;
            $softUnknown = false;
            foreach ($criteria as $criterion) {
                if ($criterion->status !== 'active') {
                    continue;
                }
                $assessment = $this->assess($candidate, $criterion, $criteria);
                $assessments[] = array_merge([
                    'requirement_id' => $criterion->id,
                    'field' => $criterion->field,
                    'strength' => $criterion->strength,
                ], $assessment);
                if ($criterion->strength === 'hard' && $assessment['status'] === 'mismatch') {
                    $hardMismatch = true;
                }
                if ($criterion->strength === 'hard' && $assessment['status'] === 'unknown') {
                    $hardUnknown = true;
                }
                if ($criterion->strength === 'soft' && $assessment['status'] === 'unknown') {
                    $softUnknown = true;
                }
                if ($criterion->strength === 'soft' && $assessment['status'] === 'mismatch') {
                    $softMismatch = true;
                }
            }
            $classification = $hardMismatch ? 'not_eligible'
                : ($hardUnknown ? 'not_verified'
                    : ($softUnknown ? 'not_verified'
                        : ($softMismatch ? 'close_structured_alternative' : 'structured_exact_fit')));
            $explanations[] = [
                'product_id' => $candidate->productId,
                'classification' => $classification,
                'requirement_assessments' => $assessments,
                'meaningful_tradeoffs' => array_values(array_unique(array_map(
                    static fn (array $assessment): string => (string) $assessment['code'],
                    array_filter($assessments, static fn (array $assessment): bool => $assessment['status'] !== 'met')
                ))),
                'current_product_evidence' => $candidate->toArray(),
                'compatibility_claim_made' => false,
                'natural_language_explanation_generated' => false,
            ];
        }
        return [
            'ok' => true,
            'explanations' => $explanations,
            'unavailable_product_ids' => $set->unavailableIds,
            'complete' => $set->unavailableIds === [],
            'evidence_grounded' => true,
        ];
    }

    /** @param array<int, RequirementCriterion> $criteria @return array<string, mixed> */
    private function filterSet(ProductCandidateSet $set, array $criteria): array
    {
        $hard = array_values(array_filter(
            $criteria,
            static fn (RequirementCriterion $criterion): bool => $criterion->status === 'active' && $criterion->strength === 'hard'
        ));
        $eligible = [];
        $rejected = [];
        $unresolved = [];
        foreach ($set->candidates as $candidate) {
            $reasons = [];
            if ($this->requiresExactConfiguration($candidate)) {
                $reasons[] = [
                    'status' => 'unknown',
                    'code' => 'exact_variation_required',
                    'evidence' => $this->commerceEvidence($candidate),
                ];
            }
            if (!$candidate->visible) {
                $reasons[] = ['status' => 'mismatch', 'code' => 'product_not_visible', 'evidence' => $this->commerceEvidence($candidate)];
            }
            if (!$candidate->purchasable) {
                $reasons[] = ['status' => 'mismatch', 'code' => 'product_not_purchasable', 'evidence' => $this->commerceEvidence($candidate)];
            }
            if (!$candidate->inStock && !$candidate->backordersAllowed) {
                $reasons[] = ['status' => 'mismatch', 'code' => 'product_out_of_stock', 'evidence' => $this->commerceEvidence($candidate)];
            }
            foreach ($hard as $criterion) {
                $assessment = $this->assess($candidate, $criterion, $criteria);
                if ($assessment['status'] !== 'met') {
                    $reasons[] = array_merge(['requirement_id' => $criterion->id, 'field' => $criterion->field], $assessment);
                    if ($assessment['status'] === 'unknown') {
                        $unresolved[$criterion->id] = [
                            'requirement_id' => $criterion->id,
                            'field' => $criterion->field,
                            'code' => $assessment['code'],
                        ];
                    }
                }
            }
            if ($reasons === []) {
                $eligible[] = $candidate->toArray();
            } else {
                $rejected[] = ['product_id' => $candidate->productId, 'reasons' => $reasons];
            }
        }
        return [
            'eligible_candidates' => $eligible,
            'rejected_candidates' => $rejected,
            'unavailable_product_ids' => $set->unavailableIds,
            'unresolved_hard_requirements' => array_values($unresolved),
            'complete' => $set->unavailableIds === [] && $unresolved === [],
            'hard_filter_authority' => 'woocommerce_runtime_and_explicit_structured_requirements',
            'compatibility_assumed' => false,
        ];
    }

    /** @param array<int, RequirementCriterion> $all @return array<string, mixed> */
    private function assess(ProductCandidate $candidate, RequirementCriterion $criterion, array $all): array
    {
        $evidence = $this->commerceEvidence($candidate);
        $value = $criterion->value;
        if ($criterion->field === 'budget') {
            if (!is_array($value) || !isset($value['amount'], $value['currency'], $value['scope'])
                || !is_numeric($value['amount']) || (float) $value['amount'] < 0 || !is_string($value['currency'])
                || !in_array($value['scope'], ['per_item', 'total'], true)
                || $candidate->unitPrice === null || $candidate->currency !== $value['currency']) {
                return ['status' => 'unknown', 'code' => 'budget_evidence_incomplete', 'evidence' => $evidence];
            }
            $observed = $candidate->unitPrice;
            if ($value['scope'] === 'total') {
                $quantity = $this->quantity($all);
                if ($quantity === null) {
                    return ['status' => 'unknown', 'code' => 'total_budget_quantity_unknown', 'evidence' => $evidence];
                }
                $observed *= $quantity;
            }
            $expected = (float) $value['amount'];
            $met = match ($criterion->operator) {
                'max' => $observed <= $expected,
                'min' => $observed >= $expected,
                'equals' => abs($observed - $expected) < 0.00001,
                default => null,
            };
            return $met === null
                ? ['status' => 'unknown', 'code' => 'budget_operator_unsupported', 'evidence' => $evidence]
                : ['status' => $met ? 'met' : 'mismatch', 'code' => $met ? 'budget_met' : 'budget_not_met', 'evidence' => array_merge($evidence, ['observed_amount' => $observed, 'currency' => $candidate->currency])];
        }
        if ($criterion->field === 'quantity') {
            $quantity = $this->numericValue($value);
            if ($quantity === null || $quantity <= 0) {
                return ['status' => 'unknown', 'code' => 'quantity_invalid', 'evidence' => []];
            }
            $quantityEvidence = array_merge($evidence, [
                'quantity' => $quantity,
                'minimum_quantity' => $candidate->minimumQuantity,
                'maximum_quantity' => $candidate->maximumQuantity,
                'stock_quantity' => $candidate->stockQuantity,
                'manages_stock' => $candidate->managesStock,
                'sold_individually' => $candidate->soldIndividually,
            ]);
            if ($quantity < $candidate->minimumQuantity) {
                return ['status' => 'mismatch', 'code' => 'quantity_below_minimum', 'evidence' => $quantityEvidence];
            }
            if ($candidate->maximumQuantity !== null && $quantity > $candidate->maximumQuantity) {
                return ['status' => 'mismatch', 'code' => 'quantity_above_maximum', 'evidence' => $quantityEvidence];
            }
            if ($candidate->soldIndividually && $quantity > 1) {
                return ['status' => 'mismatch', 'code' => 'quantity_sold_individually', 'evidence' => $quantityEvidence];
            }
            if ($candidate->managesStock && !$candidate->backordersAllowed) {
                if ($candidate->stockQuantity === null) {
                    return ['status' => 'unknown', 'code' => 'quantity_stock_not_verified', 'evidence' => $quantityEvidence];
                }
                if ($candidate->stockQuantity < $quantity) {
                    return ['status' => 'mismatch', 'code' => 'quantity_stock_insufficient', 'evidence' => $quantityEvidence];
                }
            }
            return ['status' => 'met', 'code' => 'quantity_available', 'evidence' => $quantityEvidence];
        }
        if ($criterion->field === 'product_type') {
            return $this->exactListAssessment($candidate->productType, $criterion, 'product_type', $evidence);
        }
        if ($criterion->field === 'category') {
            return $this->setAssessment($candidate->categories, $criterion, 'category', $evidence);
        }
        if ($criterion->field === 'attribute') {
            if (!is_array($value) || !is_string($value['name'] ?? null) || !is_array($value['values'] ?? null)) {
                return ['status' => 'unknown', 'code' => 'attribute_requirement_invalid', 'evidence' => $evidence];
            }
            $name = strtolower(trim($value['name']));
            $required = array_values(array_filter($value['values'], 'is_string'));
            if ($required === []) {
                return ['status' => 'unknown', 'code' => 'attribute_requirement_invalid', 'evidence' => $evidence];
            }
            $actual = $candidate->attributes[$name] ?? null;
            if (!is_array($actual)) {
                return ['status' => 'unknown', 'code' => 'attribute_metadata_missing', 'evidence' => $evidence];
            }
            $intersection = array_intersect($required, $actual);
            $met = $criterion->operator === 'requires'
                ? count($intersection) === count($required)
                : $intersection !== [];
            return ['status' => $met ? 'met' : 'mismatch', 'code' => $met ? 'attribute_met' : 'attribute_not_met', 'evidence' => array_merge($evidence, ['attribute' => $name, 'observed_values' => $actual])];
        }
        if ($criterion->field === 'product_id') {
            return $this->exactListAssessment($candidate->productId, $criterion, 'product_id', $evidence);
        }
        if ($criterion->field === 'stock') {
            if (!is_bool($value) && !in_array($value, ['in_stock', 'out_of_stock'], true)) {
                return ['status' => 'unknown', 'code' => 'stock_requirement_invalid', 'evidence' => $evidence];
            }
            if (!in_array($criterion->operator, ['equals', 'not_equals'], true)) {
                return ['status' => 'unknown', 'code' => 'stock_operator_unsupported', 'evidence' => $evidence];
            }
            $expected = is_bool($value) ? $value : $value === 'in_stock';
            $actual = $candidate->inStock || $candidate->backordersAllowed;
            $met = $criterion->operator === 'not_equals' ? $actual !== $expected : $actual === $expected;
            return ['status' => $met ? 'met' : 'mismatch', 'code' => $met ? 'stock_requirement_met' : 'stock_requirement_not_met', 'evidence' => $evidence];
        }
        if ($criterion->field === 'purchasable') {
            if (!is_bool($value)) {
                return ['status' => 'unknown', 'code' => 'purchasability_requirement_invalid', 'evidence' => $evidence];
            }
            if (!in_array($criterion->operator, ['equals', 'not_equals'], true)) {
                return ['status' => 'unknown', 'code' => 'purchasability_operator_unsupported', 'evidence' => $evidence];
            }
            $met = $criterion->operator === 'not_equals' ? $candidate->purchasable !== $value : $candidate->purchasable === $value;
            return ['status' => $met ? 'met' : 'mismatch', 'code' => $met ? 'purchasability_met' : 'purchasability_not_met', 'evidence' => $evidence];
        }
        if ($criterion->field === 'exclusion' || $criterion->field === 'already_owned') {
            if (!is_array($value)) {
                return ['status' => 'unknown', 'code' => 'exclusion_requirement_invalid', 'evidence' => $evidence];
            }
            $productIds = is_array($value['product_ids'] ?? null) ? array_map('intval', $value['product_ids']) : [];
            $categories = is_array($value['categories'] ?? null) ? array_values(array_filter($value['categories'], 'is_string')) : [];
            $excluded = in_array($candidate->productId, $productIds, true) || array_intersect($candidate->categories, $categories) !== [];
            return ['status' => $excluded ? 'mismatch' : 'met', 'code' => $excluded ? 'candidate_explicitly_excluded' : 'candidate_not_excluded', 'evidence' => $evidence];
        }
        if ($criterion->field === 'compatibility') {
            return ['status' => 'unknown', 'code' => 'compatibility_evidence_required', 'evidence' => array_merge($evidence, ['compatibility_verified' => false])];
        }
        return ['status' => 'unknown', 'code' => 'requirement_not_deterministically_supported', 'evidence' => $evidence];
    }

    /** @return array<string, mixed> */
    private function exactListAssessment(string|int $actual, RequirementCriterion $criterion, string $label, array $evidence): array
    {
        if (!in_array($criterion->operator, ['equals', 'in', 'any_of', 'not_in', 'excludes', 'not_equals'], true)) {
            return ['status' => 'unknown', 'code' => $label . '_operator_unsupported', 'evidence' => $evidence];
        }
        $values = is_array($criterion->value) ? $criterion->value : [$criterion->value];
        $strict = in_array($actual, $values, true);
        $met = in_array($criterion->operator, ['not_in', 'excludes', 'not_equals'], true) ? !$strict : $strict;
        return ['status' => $met ? 'met' : 'mismatch', 'code' => $met ? $label . '_met' : $label . '_not_met', 'evidence' => array_merge($evidence, ['observed_' . $label => $actual])];
    }

    /** @param array<int, string> $actual */
    private function setAssessment(array $actual, RequirementCriterion $criterion, string $label, array $evidence): array
    {
        if (!in_array($criterion->operator, ['equals', 'in', 'any_of', 'contains', 'requires', 'not_in', 'excludes'], true)) {
            return ['status' => 'unknown', 'code' => $label . '_operator_unsupported', 'evidence' => $evidence];
        }
        $values = is_array($criterion->value) ? $criterion->value : [$criterion->value];
        $values = array_values(array_filter($values, 'is_string'));
        if ($values === []) {
            return ['status' => 'unknown', 'code' => $label . '_requirement_invalid', 'evidence' => $evidence];
        }
        $intersection = array_values(array_intersect($values, $actual));
        $met = match ($criterion->operator) {
            'not_in', 'excludes' => $intersection === [],
            'requires' => array_diff($values, $actual) === [],
            'equals' => array_diff($values, $actual) === [] && array_diff($actual, $values) === [],
            default => $intersection !== [],
        };
        return ['status' => $met ? 'met' : 'mismatch', 'code' => $met ? $label . '_met' : $label . '_not_met', 'evidence' => array_merge($evidence, ['observed_categories' => $actual])];
    }

    private function requiresExactConfiguration(ProductCandidate $candidate): bool
    {
        return $candidate->productType === 'variable';
    }

    /** @param array<int, RequirementCriterion> $criteria */
    private function quantity(array $criteria): ?float
    {
        foreach (array_reverse($criteria) as $criterion) {
            if ($criterion->status === 'active' && $criterion->field === 'quantity') {
                return $this->numericValue($criterion->value);
            }
        }
        return null;
    }

    private function numericValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $number = (float) $value;
            return is_finite($number) ? $number : null;
        }
        if (is_array($value) && isset($value['amount']) && is_numeric($value['amount'])) {
            $number = (float) $value['amount'];
            return is_finite($number) ? $number : null;
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function commerceEvidence(ProductCandidate $candidate): array
    {
        return [
            'source' => 'woocommerce_runtime',
            'product_id' => $candidate->productId,
            'observed_at' => $candidate->observedAt,
            'visible' => $candidate->visible,
            'purchasable' => $candidate->purchasable,
            'in_stock' => $candidate->inStock,
            'backorders_allowed' => $candidate->backordersAllowed,
            'unit_price' => $candidate->unitPrice,
            'currency' => $candidate->currency,
            'minimum_quantity' => $candidate->minimumQuantity,
            'maximum_quantity' => $candidate->maximumQuantity,
            'stock_quantity' => $candidate->stockQuantity,
            'manages_stock' => $candidate->managesStock,
            'sold_individually' => $candidate->soldIndividually,
        ];
    }

    /** @param array<int, mixed> $values @return array<int, RequirementCriterion>|null */
    private function requirements(array $values): ?array
    {
        if ($values === [] || count($values) > 64) {
            return null;
        }
        $result = [];
        $ids = [];
        try {
            foreach ($values as $value) {
                if (!is_array($value)) {
                    return null;
                }
                $criterion = RequirementCriterion::fromStored($value);
                if (isset($ids[$criterion->id])) {
                    return null;
                }
                $ids[$criterion->id] = true;
                $result[] = $criterion;
            }
        } catch (\Throwable) {
            return null;
        }
        return $result;
    }

    /** @param array<int, mixed> $values @return array<int, int>|null */
    private function candidateIds(array $values): ?array
    {
        if ($values === [] || count($values) > self::MAXIMUM_CANDIDATES) {
            return null;
        }
        $ids = [];
        foreach ($values as $value) {
            if (!is_int($value) || $value < 1 || isset($ids[$value])) {
                return null;
            }
            $ids[$value] = true;
        }
        return array_keys($ids);
    }

    /**
     * Validate only the server-generated rank output used by diversification.
     * The method is private and retains the exact candidate snapshot, so no
     * second Woo read can mix facts with first-snapshot scores.
     *
     * @param array<int, mixed> $values
     * @return array<int, array{product_id:int,score:float,candidate:array<string,mixed>}>|null
     */
    private function serverRankedInput(array $values): ?array
    {
        if ($values === [] || count($values) > self::MAXIMUM_CANDIDATES) {
            return null;
        }
        $result = [];
        $ids = [];
        foreach ($values as $value) {
            if (!is_array($value) || !is_int($value['product_id'] ?? null)
                || $value['product_id'] < 1 || isset($ids[$value['product_id']])
                || (!is_int($value['score'] ?? null) && !is_float($value['score'] ?? null))
                || !is_finite((float) $value['score']) || $value['score'] < 0 || $value['score'] > 100
                || !is_array($value['candidate'] ?? null)
                || ($value['candidate']['product_id'] ?? null) !== $value['product_id']
                || !is_string($value['candidate']['product_type'] ?? null)
                || $value['candidate']['product_type'] === ''
                || !is_array($value['candidate']['categories'] ?? null)
                || !array_is_list($value['candidate']['categories'])
                || array_filter($value['candidate']['categories'], static fn (mixed $category): bool => !is_string($category)) !== []
                || (!is_int($value['candidate']['unit_price'] ?? null)
                    && !is_float($value['candidate']['unit_price'] ?? null)
                    && ($value['candidate']['unit_price'] ?? null) !== null)
                || (is_numeric($value['candidate']['unit_price'] ?? null)
                    && !is_finite((float) $value['candidate']['unit_price']))
            ) {
                return null;
            }
            $ids[$value['product_id']] = true;
            $result[] = [
                'product_id' => $value['product_id'],
                'score' => (float) $value['score'],
                'candidate' => $value['candidate'],
            ];
        }
        return $result;
    }

    /** @param array<int, array<string, mixed>> $ranked @return array<int, array{score:float,product_ids:array<int,int>}> */
    private function tieGroups(array $ranked): array
    {
        $groups = [];
        foreach ($ranked as $item) {
            $key = number_format((float) $item['score'], 3, '.', '');
            if (!isset($groups[$key])) {
                $groups[$key] = ['score' => (float) $item['score'], 'product_ids' => []];
            }
            $groups[$key]['product_ids'][] = (int) $item['product_id'];
        }
        return array_values($groups);
    }

    /** @param array<int, array<string, mixed>> $ranked @return array<int, string> */
    private function unscoredSoft(array $ranked): array
    {
        $ids = [];
        foreach ($ranked as $candidate) {
            foreach ($candidate['score_components'] as $component) {
                if (($component['status'] ?? null) === 'unknown' && isset($component['requirement_id'])) {
                    $ids[] = (string) $component['requirement_id'];
                }
            }
        }
        return array_values(array_unique($ids));
    }

    private function priceBand(?float $price): string
    {
        if ($price === null) {
            return 'unknown';
        }
        if ($price < 25) {
            return 'under_25';
        }
        if ($price < 100) {
            return '25_to_99';
        }
        if ($price < 500) {
            return '100_to_499';
        }
        return '500_plus';
    }
}
