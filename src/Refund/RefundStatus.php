<?php

declare(strict_types=1);

namespace Enigmoss\Refund;

enum RefundStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
