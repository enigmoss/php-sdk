<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Laravel\Events;

use Enigmoss\Event\WebhookEvent;

/**
 * A verified Enigmoss webhook. Listen to it and match on the event class:
 *
 *     Event::listen(function (WebhookReceived $received) {
 *         if ($received->event instanceof PaymentEvent && $received->event->status()->isPaid()) { ... }
 *     });
 */
final class WebhookReceived
{
    public function __construct(
        public readonly WebhookEvent $event,
        public readonly string $connection,
    ) {}
}
