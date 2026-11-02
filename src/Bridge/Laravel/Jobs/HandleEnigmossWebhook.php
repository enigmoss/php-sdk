<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Laravel\Jobs;

use Enigmoss\Bridge\Laravel\Events\WebhookReceived;
use Enigmoss\Event\WebhookEvent;
use Enigmoss\Webhook\WebhookDeduplicatorInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fires WebhookReceived for an already verified webhook. It carries the raw body, not the event
 * object: a queue payload must not hold SDK objects or keys.
 */
final class HandleEnigmossWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly string $rawBody,
        public readonly string $connectionName,
    ) {}

    public function handle(Dispatcher $events): void
    {
        $decoded = json_decode($this->rawBody, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return;
        }

        $events->dispatch(new WebhookReceived(WebhookEvent::fromEnvelope($decoded), $this->connectionName));
    }

    /**
     * The job failed for good: release the deduplication claim so that a redelivery of the event
     * (automatic or events->redeliver()) is handled again instead of being dropped as a duplicate.
     */
    public function failed(?\Throwable $exception = null): void
    {
        $decoded = json_decode($this->rawBody, true);
        $eventId = \is_array($decoded) && \is_string($decoded['event_id'] ?? null) ? $decoded['event_id'] : '';
        if ($eventId !== '') {
            Container::getInstance()->make(WebhookDeduplicatorInterface::class)->release($eventId);
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }
}
