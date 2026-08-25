<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Domain;

enum IdempotencyDecisionStatus: string
{
    case Claimed = 'claimed';
    case Replay = 'replay';
    case Conflict = 'conflict';
    case InProgress = 'in_progress';
    case ReconcileRequired = 'reconcile_required';
}

