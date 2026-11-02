<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Support;

use Enigmoss\Retry\ClockInterface;
use Enigmoss\Retry\SleeperInterface;

/**
 * A frozen clock that also acts as the sleeper: sleeping advances time.
 */
final class FakeClock implements ClockInterface, SleeperInterface
{
    /** @var list<float> */
    public array $sleeps = [];

    public function __construct(public float $time = 1767225600.0) {}

    public function now(): float
    {
        return $this->time;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->time += $seconds;
    }
}
