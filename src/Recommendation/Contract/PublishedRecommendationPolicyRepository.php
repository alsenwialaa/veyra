<?php
declare(strict_types=1);

namespace Veyra\Recommendation\Contract;

use Veyra\Recommendation\Domain\RecommendationPolicy;

interface PublishedRecommendationPolicyRepository
{
    public function published(): ?RecommendationPolicy;
}
