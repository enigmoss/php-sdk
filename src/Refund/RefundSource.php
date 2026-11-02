<?php

declare(strict_types=1);

namespace Enigmoss\Refund;

enum RefundSource: string
{
    case Payment = 'payment';
    case Aml = 'aml';
    case BlockedWallet = 'blocked_wallet';
    case Unknown = 'unknown';
}
