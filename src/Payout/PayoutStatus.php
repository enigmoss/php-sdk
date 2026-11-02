<?php

declare(strict_types=1);

namespace Enigmoss\Payout;

/**
 * Payout status. Rely on Payout::$isFinal rather than on the status alone.
 */
enum PayoutStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    /** Waits for manual approval (large amount). */
    case AwaitingApproval = 'awaiting_approval';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';
}
