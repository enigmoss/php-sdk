<?php

declare(strict_types=1);

namespace Enigmoss\Event;

/**
 * webhook.test: sent by webhooks->sendTest(); carries no object.
 */
final class TestEvent extends WebhookEvent {}
