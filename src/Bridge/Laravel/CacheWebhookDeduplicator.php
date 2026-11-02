<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Laravel;

use Enigmoss\Webhook\WebhookDeduplicatorInterface;
use Illuminate\Contracts\Cache\Repository;

/**
 * Atomic on stores with an atomic add (redis, memcached, database, array).
 */
final class CacheWebhookDeduplicator implements WebhookDeduplicatorInterface
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $ttl = 259200,
        private readonly string $prefix = 'enigmoss:webhook:',
    ) {}

    public function claim(string $eventId): bool
    {
        return $this->cache->add($this->prefix . $eventId, 1, $this->ttl);
    }

    public function release(string $eventId): void
    {
        $this->cache->forget($this->prefix . $eventId);
    }
}
