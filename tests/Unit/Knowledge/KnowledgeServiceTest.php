<?php
declare(strict_types=1);

namespace Veyra\Tests\Unit\Knowledge;

use PHPUnit\Framework\TestCase;
use Veyra\Knowledge\Application\KnowledgeService;
use Veyra\Knowledge\Contract\PublishedKnowledgeRepository;
use Veyra\Knowledge\Domain\PublishedKnowledgeIndex;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Support\FrozenClock;

final class KnowledgeServiceTest extends TestCase
{
    public function testSearchUsesOnlyFreshPublishedGovernedSources(): void
    {
        $index = PublishedKnowledgeIndex::fromPublishedPayload([
            'schema_version' => '1.0.0',
            'status' => 'published',
            'publication_id' => 'pub-1',
            'version' => '7',
            'store_id' => 1,
            'published_at' => '2026-08-20T00:00:00Z',
            'sources' => [
                $this->source('shipping-current', 'shipping_policy', 'الشحن إلى عدن متاح', '2026-01-01T00:00:00Z', '2027-01-01T00:00:00Z'),
                $this->source('shipping-expired', 'shipping_policy', 'الشحن القديم', '2025-01-01T00:00:00Z', '2026-01-01T00:00:00Z'),
            ],
        ], 1);
        $repository = new class($index) implements PublishedKnowledgeRepository {
            public function __construct(private readonly PublishedKnowledgeIndex $index) {}
            public function published(): ?PublishedKnowledgeIndex { return $this->index; }
        };
        $service = new KnowledgeService(
            $repository,
            new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'))
        );

        $result = $service->search('الشحن', 'guest', 'ar-YE', null, null, ['shipping_policy'], 8);

        self::assertTrue($result['ok']);
        self::assertSame(1, $result['count']);
        self::assertSame('shipping-current', $result['results'][0]['source_id']);
        self::assertFalse($result['results'][0]['embedded_instructions_authorized']);
    }

    /** @return array<string, mixed> */
    private function source(string $id, string $type, string $content, string $effective, string $expires): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'version' => '1',
            'title' => $content,
            'content' => $content,
            'language' => 'ar',
            'owner' => 'merchant',
            'authority' => 'authoritative_policy',
            'scope' => 'public',
            'status' => 'approved',
            'effective_from' => $effective,
            'expires_at' => $expires,
            'approved_at' => '2026-01-01T00:00:00Z',
            'citations' => [['citation_id' => $id . '-citation', 'label' => $content]],
            'data_classification' => 'public',
            'injection_treatment' => 'content_only',
        ];
    }
}
