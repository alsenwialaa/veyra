<?php
declare(strict_types=1);

namespace Veyra\Recommendation\Infrastructure;

use Veyra\Recommendation\Contract\PublishedRecommendationPolicyRepository;
use Veyra\Recommendation\Domain\RecommendationPolicy;

final class WordPressPublishedRecommendationPolicyRepository implements PublishedRecommendationPolicyRepository
{
    public const OPTION = 'veyra_published_recommendation_policy';

    public function __construct(private readonly string $optionName = self::OPTION)
    {
    }

    public function published(): ?RecommendationPolicy
    {
        if (!function_exists('get_option')) {
            return null;
        }
        $payload = get_option($this->optionName, null);
        if (!is_array($payload)) {
            return null;
        }
        $storeId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        try {
            return RecommendationPolicy::fromPublishedPayload($payload, $storeId);
        } catch (\Throwable) {
            return null;
        }
    }
}
