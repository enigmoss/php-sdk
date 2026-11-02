<?php

declare(strict_types=1);

namespace Enigmoss\Http;

/**
 * Receives client events. Must not throw; exceptions from observers are swallowed.
 */
interface ObserverInterface
{
    public function onEvent(ClientEvent $event): void;
}
