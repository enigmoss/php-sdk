<?php

declare(strict_types=1);

namespace Enigmoss\Payout;

/**
 * Network fee priority of a payout; higher priority confirms faster and costs more.
 */
enum PayoutPriority: string
{
    case Recommended = 'recommended';
    case Economy = 'economy';
    case High = 'high';
    case Highest = 'highest';
    case Unknown = 'unknown';
}
