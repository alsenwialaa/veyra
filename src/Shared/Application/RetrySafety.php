<?php

declare(strict_types=1);

namespace Veyra\Shared\Application;

enum RetrySafety: string
{
    case Safe = 'safe';
    case Unsafe = 'unsafe';
    case ReconcileRequired = 'reconcile_required';
    case NotApplicable = 'not_applicable';
}

