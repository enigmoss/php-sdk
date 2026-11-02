<?php

declare(strict_types=1);

namespace Enigmoss\Payment;

enum AmlVerificationLinkStatus: string
{
    case Init = 'init';
    case Pending = 'pending';
    case Completed = 'completed';
    case Unknown = 'unknown';
}
