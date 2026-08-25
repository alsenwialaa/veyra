<?php
declare(strict_types=1);

namespace Veyra\AI\Orchestration;

use Veyra\AI\Contract\ToolResult;

/** Classifies what may truthfully be said when orchestration fails after writes. */
final class MutationFailureOutcome
{
    /** @param array<int, ToolResult> $results */
    public static function classify(array $results): string
    {
        $knownChange = false;
        foreach ($results as $result) {
            if (!$result instanceof ToolResult) {
                continue;
            }
            if ($result->status === 'uncertain') {
                return 'uncertain';
            }
            // This classifier receives only write/sensitive-write results from
            // CommerceAgent. A succeeded write may be an idempotent no-op, but
            // it is still a completed store operation and must never fall
            // through to the claim that no action ran.
            if (in_array($result->status, ['partial', 'succeeded'], true)) {
                $knownChange = true;
            }
        }

        return $knownChange ? 'partial' : 'none';
    }
}
