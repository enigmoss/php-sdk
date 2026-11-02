<?php

declare(strict_types=1);

namespace Enigmoss\Retry;

final class SystemClock implements ClockInterface
{
    public function now(): float
    {
        return microtime(true);
    }
}
