<?php

declare(strict_types=1);

namespace Enigmoss\Payment;

/**
 * Invoice status. Use Payment::$isFinal to decide whether the status can still change; new
 * statuses may be added in minor releases and arrive as Unknown in this version.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Confirming = 'confirming';
    case Paid = 'paid';
    case Overpaid = 'overpaid';
    case Underpaid = 'underpaid';
    case UnderpaidWaiting = 'underpaid_waiting';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Locked = 'locked';
    case Refunding = 'refunding';
    case Refunded = 'refunded';
    case RefundFailed = 'refund_failed';
    case Unknown = 'unknown';

    /** The customer paid at least the invoice amount. */
    public function isPaid(): bool
    {
        return $this === self::Paid || $this === self::Overpaid;
    }
}
