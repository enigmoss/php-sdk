<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Laravel;

use Enigmoss\Http\OriginStoreInterface;
use Illuminate\Contracts\Cache\Repository;

/**
 * Keeps the API origin learned from the fallback host in the Laravel cache, so every worker
 * and every PHP-FPM request uses it. The service provider wires it to the default cache store
 * for connections that set "fallback_base_url".
 */
final class CacheOriginStore implements OriginStoreInterface
{
    public function __construct(private readonly Repository $cache) {}

    public function get(string $key): ?string
    {
        $value = $this->cache->get($key);

        return \is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, int $ttl): void
    {
        $this->cache->put($key, $value, $ttl);
    }
}
