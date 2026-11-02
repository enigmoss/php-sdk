<?php

declare(strict_types=1);

namespace Enigmoss\Auth;

/**
 * Which API key an endpoint accepts.
 */
enum KeyScope: string
{
    /** Payments, static wallets, balances, transactions, payment method settings, webhook events. */
    case Payment = 'payment';

    /** Payouts, refunds and transfers. */
    case Payout = 'payout';

    /** Reference data readable with either key; the payment key is used when both are configured. */
    case Any = 'any';
}
