<?php
declare(strict_types=1);

namespace Veyra\Knowledge\Contract;

use Veyra\Knowledge\Domain\PublishedKnowledgeIndex;

interface PublishedKnowledgeRepository
{
    /**
     * Return the one explicitly published, store-scoped index. Drafts and raw
     * WordPress content are never valid retrieval sources.
     */
    public function published(): ?PublishedKnowledgeIndex;
}
