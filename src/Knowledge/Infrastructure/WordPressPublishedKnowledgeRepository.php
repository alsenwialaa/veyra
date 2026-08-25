<?php
declare(strict_types=1);

namespace Veyra\Knowledge\Infrastructure;

use Veyra\Knowledge\Contract\PublishedKnowledgeRepository;
use Veyra\Knowledge\Domain\PublishedKnowledgeIndex;

final class WordPressPublishedKnowledgeRepository implements PublishedKnowledgeRepository
{
    public const OPTION = 'veyra_published_knowledge_index';

    public function __construct(private readonly string $optionName = self::OPTION)
    {
    }

    public function published(): ?PublishedKnowledgeIndex
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
            return PublishedKnowledgeIndex::fromPublishedPayload($payload, $storeId);
        } catch (\Throwable) {
            // A partially valid or draft index must never become an evidence source.
            return null;
        }
    }
}
