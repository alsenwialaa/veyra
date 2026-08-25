<?php

declare(strict_types=1);

namespace Veyra\Http;

use Veyra\Shared\Domain\CorrelationId;

final class Correlation
{
    /**
     * Client correlation values are diagnostic hints only. A server UUID is
     * always minted so security records cannot be collided by request input.
     */
    public static function forRequest(\WP_REST_Request $request): CorrelationId
    {
        unset($request);
        return CorrelationId::generate();
    }
}
