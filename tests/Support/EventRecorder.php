<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Support;

use Enigmoss\Http\ClientEvent;
use Enigmoss\Http\ClientEventType;
use Enigmoss\Http\ObserverInterface;

/**
 * An observer that keeps every event of the client.
 */
final class EventRecorder implements ObserverInterface
{
    /** @var list<ClientEvent> */
    public array $events = [];

    public function onEvent(ClientEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<ClientEventType>
     */
    public function types(): array
    {
        return array_map(static fn(ClientEvent $e): ClientEventType => $e->type, $this->events);
    }

    /**
     * @return list<ClientEvent>
     */
    public function ofType(ClientEventType $type): array
    {
        return array_values(array_filter($this->events, static fn(ClientEvent $e): bool => $e->type === $type));
    }
}
