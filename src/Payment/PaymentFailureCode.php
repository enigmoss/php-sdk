<?php

declare(strict_types=1);

namespace Enigmoss\Payment;

enum PaymentFailureCode: string
{
    case PaymentFailed = 'payment_failed';
    case ProcessingError = 'processing_error';
    case Unknown = 'unknown';
}
