<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Application;

use Veyra\Confirmation\Domain\SensitiveActionGateResult;

final class SensitiveActionRollback extends \RuntimeException
{
    public function __construct(public readonly SensitiveActionGateResult $result)
    {
        parent::__construct($result->code);
    }
}

