<?php
declare(strict_types=1);

namespace Veyra\Privacy;

/**
 * Store-independent contract for the deterministic projection applied before
 * content is included in an attested provider Context Bundle.
 */
interface ProviderOutboundSanitizer
{
    /**
     * @return array{value:mixed,classifications:list<string>,redactions:list<string>}
     */
    public function redact(mixed $value): array;
}
