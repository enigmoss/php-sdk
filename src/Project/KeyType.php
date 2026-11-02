<?php

declare(strict_types=1);

namespace Enigmoss\Project;

enum KeyType: string
{
    case Payment = 'payment';
    case Payout = 'payout';
    case Unknown = 'unknown';
}
