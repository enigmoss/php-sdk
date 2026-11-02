<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Symfony\Webhook;

use Enigmoss\Webhook\WebhookDeduplicatorInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Remembers claimed event IDs in a PSR-6 cache pool (cache.app by default).
 *
 * PSR-6 has no atomic add: two deliveries at the same instant can both claim. Use
 * Enigmoss\Webhook\PdoWebhookDeduplicator (bind it to WebhookDeduplicatorInterface) when the
 * handler is not idempotent itself.
 */
final class CacheWebhookDeduplicator implements WebhookDeduplicatorInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly int $ttl = 259200,
    ) {}

    public function claim(string $eventId): bool
    {
        $item = $this->cache->getItem($this->key($eventId));
        if ($item->isHit()) {
            return false;
        }

        return $this->cache->save($item->set(1)->expiresAfter($this->ttl));
    }

    public function release(string $eventId): void
    {
        $this->cache->deleteItem($this->key($eventId));
    }

    private function key(string $eventId): string
    {
        // PSR-6 forbids some characters in keys; event IDs are UUIDs, hash anyway for safety.
        return 'enigmoss_webhook_' . hash('sha256', $eventId);
    }
}
