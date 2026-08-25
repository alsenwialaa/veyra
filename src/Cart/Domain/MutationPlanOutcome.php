<?php

declare(strict_types=1);

namespace Veyra\Cart\Domain;

/** Pure truth mapping for explicit-partial compound cart execution. */
final class MutationPlanOutcome
{
    /** @param list<array<string, mixed>> $results @return array<string, mixed> */
    public static function fromResults(array $results): array
    {
        if ($results === []) {
            return [
                'ok' => false,
                'code' => 'cart_plan_failed',
                'result_status' => 'failed',
                'semantics' => 'explicit_partial',
                'results' => [],
                'success_count' => 0,
                'failure_count' => 0,
            ];
        }
        $failed = count(array_filter(
            $results,
            static fn (array $result): bool => ($result['result']['ok'] ?? false) !== true
        ));
        $succeeded = count($results) - $failed;
        $status = $failed === 0 ? 'succeeded' : ($succeeded === 0 ? 'failed' : 'partial');

        return [
            'ok' => $status !== 'failed',
            'code' => 'cart_plan_' . $status,
            'result_status' => $status,
            'semantics' => 'explicit_partial',
            'results' => $results,
            'success_count' => $succeeded,
            'failure_count' => $failed,
        ];
    }
}
