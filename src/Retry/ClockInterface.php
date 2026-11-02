<?php

declare(strict_types=1);

namespace Enigmoss\Retry;

interface ClockInterface
{
    /** Unix time with microseconds. */
    public function now(): float;
}
