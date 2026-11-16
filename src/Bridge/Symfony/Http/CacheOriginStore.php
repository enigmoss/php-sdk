<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Symfony\Http;

use Enigmoss\Http\OriginStoreInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Keeps the API origin learned from the fallback host in a PSR-6 cache pool (cache.app by
 * default), so every worker and every PHP-FPM request uses it. The bundle wires it for clients
 * that set "fallback_base_url".
 */
final class CacheOriginStore implements OriginStoreInterface
{
    public function __construct(private readonly CacheItemPoolInterface $cache) {}

    public function get(string $key): ?string
    {
        $item = $this->cache->getItem($key);
        $value = $item->isHit() ? $item->get() : null;

        return \is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, int $ttl): void
    {
        $this->cache->save($this->cache->getItem($key)->set($value)->expiresAfter($ttl));
    }
}
