<?php

declare(strict_types=1);

namespace Enigmoss\Refund;

enum RefundFailureCode: string
{
    case PayoutFailed = 'payout_failed';
    case SystemError = 'system_error';
    case AmlRefundFailed = 'aml_refund_failed';
    case GatewayRejected = 'gateway_rejected';
    case Unknown = 'unknown';
}
