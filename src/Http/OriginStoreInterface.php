<?php

declare(strict_types=1);

namespace Enigmoss\Http;

/**
 * Shares the API origin learned from the fallback host between PHP processes.
 *
 * A client keeps that origin in memory. Under PHP-FPM a client lives for one HTTP request, so
 * without a store every request would repeat the failed attempts and the fallback lookup; pass
 * a store over any shared cache (APCu, Redis, the framework cache) to ClientConfig::$originStore.
 * Adapters: Bridge\Psr16\Psr16OriginStore, Bridge\Laravel\CacheOriginStore and
 * Bridge\Symfony\Http\CacheOriginStore.
 *
 * The SDK stores one short string per project and reads it back as is. Keys are at most 64
 * characters of "a-z", "0-9" and "_". An exception thrown by the store never breaks an API call:
 * the SDK goes on with what it has in memory.
 */
interface OriginStoreInterface
{
    /**
     * @return string|null The value stored under $key; null when there is none or it expired.
     */
    public function get(string $key): ?string;

    /**
     * @param int $ttl Seconds after which the value must be forgotten; always positive.
     */
    public function set(string $key, string $value, int $ttl): void;
}
