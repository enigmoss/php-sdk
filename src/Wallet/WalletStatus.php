<?php

declare(strict_types=1);

namespace Enigmoss\Wallet;

enum WalletStatus: string
{
    case Active = 'active';
    /** Blocked: new deposits are not credited; refundable once with refunds->createForWallet(). */
    case Blocked = 'blocked';
    case Inactive = 'inactive';
    case Unknown = 'unknown';
}
