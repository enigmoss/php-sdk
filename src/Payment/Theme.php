<?php

declare(strict_types=1);

namespace Enigmoss\Payment;

/**
 * Color theme of the hosted payment page.
 */
enum Theme: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';
}
