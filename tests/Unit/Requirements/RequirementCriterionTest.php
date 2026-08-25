<?php
declare(strict_types=1);

namespace Veyra\Tests\Unit\Requirements;

use PHPUnit\Framework\TestCase;
use Veyra\Requirements\Domain\RequirementCriterion;

final class RequirementCriterionTest extends TestCase
{
    public function testStoredProvenanceContainsOnlyExactExcerptDigestAndOffsets(): void
    {
        $criterion = RequirementCriterion::proposed(
            'budget',
            'max',
            ['amount' => 50, 'currency' => 'USD', 'scope' => 'per_item'],
            'hard',
            'active',
            [
                'message_id' => 'msg-1',
                'excerpt_sha256' => hash('sha256', 'under 50'),
                'excerpt_offset_bytes' => 7,
                'excerpt_length_bytes' => 8,
                'source_kind' => 'customer_visible_message',
            ],
            [],
            '2026-08-24T10:00:00Z'
        );

        self::assertArrayNotHasKey('excerpt', $criterion->toArray()['source']);
        self::assertSame('shopper_message_exact_excerpt', $criterion->verification);
        self::assertSame('active', $criterion->status);
    }
}
