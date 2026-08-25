<?php

declare(strict_types=1);

namespace Veyra\Identity\Domain;

enum ActorType: string
{
    case Guest = 'guest';
    case Customer = 'customer';
    case Staff = 'staff';
    case System = 'system';
}

