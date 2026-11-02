<?php

declare(strict_types=1);

namespace Enigmoss\Event;

enum WebhookObjectType: string
{
    case Payment = 'payment';
    case Wallet = 'wallet';
    case Payout = 'payout';
    case Refund = 'refund';
    case Unknown = 'unknown';
}
