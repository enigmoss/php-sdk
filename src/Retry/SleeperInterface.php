<?php

declare(strict_types=1);

namespace Enigmoss\Retry;

interface SleeperInterface
{
    public function sleep(float $seconds): void;
}
