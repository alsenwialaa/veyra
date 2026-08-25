<?php

declare(strict_types=1);

namespace Veyra\Shared\Application;

enum OperationStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Partial = 'partial';
    case Uncertain = 'uncertain';
    case Blocked = 'blocked';
    case Stale = 'stale';
}

