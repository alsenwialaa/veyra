<?php

declare(strict_types=1);

namespace Veyra\CRM\Infrastructure;

/**
 * A CRM database write was acknowledged, but the authoritative row could not
 * be read back. Callers must reconcile by the known case ID and version before
 * they can report success or permit a retry.
 */
final class CaseWriteOutcomeUncertain extends \RuntimeException
{
    public function __construct(
        public readonly string $caseId,
        public readonly int $knownVersion,
        ?\Throwable $previous = null
    ) {
        parent::__construct('case_write_outcome_uncertain', 0, $previous);
    }
}
