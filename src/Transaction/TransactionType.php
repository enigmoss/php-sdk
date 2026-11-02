<?php

declare(strict_types=1);

namespace Enigmoss\Transaction;

enum TransactionType: string
{
    case Payment = 'payment';
    case PaymentHold = 'payment_hold';
    case Payout = 'payout';
    case Refund = 'refund';
    case Transfer = 'transfer';
    case ConversionOut = 'conversion_out';
    case ConversionIn = 'conversion_in';
    /** A legacy movement type; can be read but not used as a filter. */
    case Other = 'other';
    case Unknown = 'unknown';
}
