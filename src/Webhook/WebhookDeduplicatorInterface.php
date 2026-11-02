<?php

declare(strict_types=1);

namespace Enigmoss\Webhook;

/**
 * Makes webhook handling exactly-once per event id: a redelivered or concurrently delivered
 * webhook is acknowledged without being handled twice.
 *
 *     if (!$deduplicator->claim($event->id)) {
 *         return 200; // already handled or being handled
 *     }
 *     try {
 *         $queue->push($event);              // or handle it
 *     } catch (\Throwable $e) {
 *         $deduplicator->release($event->id); // let the next delivery try again
 *         throw $e;                           // answer non-2xx: the API retries
 *     }
 *
 * Implementations: PdoWebhookDeduplicator (any PDO database), the Laravel bridge (cache add),
 * the Symfony bridge (PSR-6 cache, best effort).
 */
interface WebhookDeduplicatorInterface
{
    /**
     * Atomically marks the event as taken. Returns false when it was already claimed.
     */
    public function claim(string $eventId): bool;

    /**
     * Forgets a claim whose handling failed, so a redelivery is handled again.
     */
    public function release(string $eventId): void;
}
