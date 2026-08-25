<?php
declare(strict_types=1);

namespace Veyra\Recommendation\Contract;

use Veyra\Recommendation\Domain\ProductCandidateSet;

interface ProductCandidateRepository
{
    /** @param array<int, int> $productIds */
    public function retrieve(array $productIds): ProductCandidateSet;
}
