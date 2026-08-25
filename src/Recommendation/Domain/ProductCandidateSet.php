<?php
declare(strict_types=1);

namespace Veyra\Recommendation\Domain;

final class ProductCandidateSet
{
    /** @param array<int, ProductCandidate> $candidates @param array<int, int> $unavailableIds */
    public function __construct(
        public readonly bool $commerceAvailable,
        public readonly array $candidates,
        public readonly array $unavailableIds
    ) {
    }

    public function candidate(int $productId): ?ProductCandidate
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->productId === $productId) {
                return $candidate;
            }
        }
        return null;
    }
}
