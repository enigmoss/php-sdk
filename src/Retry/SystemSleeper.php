<?php

declare(strict_types=1);

namespace Enigmoss\Retry;

final class SystemSleeper implements SleeperInterface
{
    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }
}
