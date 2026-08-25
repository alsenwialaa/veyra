<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Domain;

enum ConfirmationStatus: string
{
    case Active = 'active';
    case Consumed = 'consumed';
    case Expired = 'expired';
    case Invalidated = 'invalidated';
}

