<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Laravel\Http;

use Enigmoss\Bridge\Laravel\Jobs\HandleEnigmossWebhook;
use Enigmoss\Event\WebhookEvent;
use Enigmoss\Webhook\WebhookDeduplicatorInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Built-in webhook endpoint registered by Route::enigmossWebhooks().
 *
 * It answers quickly (the API waits 15 seconds) and hands the event to a queued job, which fires
 * Enigmoss\Bridge\Laravel\Events\WebhookReceived. The event_id is claimed atomically first; if
 * queueing fails the claim is released and the endpoint answers 500, so the API delivers again.
 * A job that fails for good releases the claim too (HandleEnigmossWebhook::failed()).
 */
final class WebhookController
{
    public function __invoke(Request $request, Dispatcher $bus, WebhookDeduplicatorInterface $deduplicator, Config $config): JsonResponse
    {
        $event = $request->attributes->get(VerifyEnigmossWebhook::EVENT_ATTRIBUTE);
        if (!$event instanceof WebhookEvent) {
            throw new \LogicException('Route::enigmossWebhooks() must run the enigmoss.webhook middleware.');
        }
        $connection = $request->attributes->get(VerifyEnigmossWebhook::EVENT_ATTRIBUTE . '_connection');
        $connection = \is_string($connection) ? $connection : 'default';

        // event_id is a UUID unique across projects; the deduplicator is replaceable via the container.
        $claimed = $event->id !== '';
        if ($claimed && !$deduplicator->claim($event->id)) {
            return new JsonResponse(['status' => 'duplicate']);
        }

        $job = new HandleEnigmossWebhook($request->getContent(), $connection);
        $queueConnection = $config->get('enigmoss.webhooks.queue_connection');
        $queue = $config->get('enigmoss.webhooks.queue');
        if (\is_string($queueConnection) && $queueConnection !== '') {
            $job->onConnection($queueConnection);
        }
        if (\is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }
        try {
            $bus->dispatch($job);
        } catch (\Throwable $e) {
            // Not queued (or, on the sync queue, a listener failed): answer 500 so the API delivers
            // the webhook again, and let that delivery pass the deduplication.
            if ($claimed) {
                $deduplicator->release($event->id);
            }

            throw $e;
        }

        return new JsonResponse(['status' => 'accepted']);
    }
}
