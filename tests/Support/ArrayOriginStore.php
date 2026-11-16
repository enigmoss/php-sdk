<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Support;

use Enigmoss\Http\OriginStoreInterface;

/**
 * A cache shared by the clients of one test, with expiry on the fake clock.
 */
final class ArrayOriginStore implements OriginStoreInterface
{
    /** @var array<string, array{string, float}> */
    public array $values = [];

    public int $reads = 0;

    public function __construct(private readonly FakeClock $clock) {}

    public function get(string $key): ?string
    {
        ++$this->reads;
        $entry = $this->values[$key] ?? null;

        return $entry !== null && $entry[1] > $this->clock->now() ? $entry[0] : null;
    }

    public function set(string $key, string $value, int $ttl): void
    {
        $this->values[$key] = [$value, $this->clock->now() + $ttl];
    }
}
