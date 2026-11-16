<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Psr16;

use Enigmoss\Http\OriginStoreInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Keeps the API origin learned from the fallback host in any PSR-16 cache (APCu, Redis,
 * Memcached, a framework cache), so PHP-FPM workers share it:
 *
 *     new ClientConfig(..., fallbackBaseUrl: $fallback, originStore: new Psr16OriginStore($cache));
 *
 * The cache must be shared by the processes that create clients; an array cache keeps nothing
 * between requests.
 */
final class Psr16OriginStore implements OriginStoreInterface
{
    public function __construct(private readonly CacheInterface $cache) {}

    public function get(string $key): ?string
    {
        $value = $this->cache->get($key);

        return \is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, int $ttl): void
    {
        $this->cache->set($key, $value, $ttl);
    }
}
