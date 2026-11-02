<?php

declare(strict_types=1);

namespace Enigmoss\Event;

/**
 * An event type this SDK version does not know. Acknowledge it with 2xx; inspect $type and data().
 */
final class UnknownEvent extends WebhookEvent {}
