<?php

declare(strict_types=1);

namespace Enigmoss\Event;

use Enigmoss\Internal\Payload;

/**
 * A webhook event from the events journal, with its delivery state.
 *
 * $payload is the same WebhookEvent your endpoint receives, so missed webhooks can be replayed
 * through the same handler:
 *
 *     foreach ($enigmoss->events->iterate(new ListEventsQuery(deliveryStatus: DeliveryStatus::Failed)) as $record) {
 *         $handler->handle($record->payload);
 *     }
 */
final class EventRecord
{
    /**
     * @internal
     *
     * @param list<DeliveryAttempt> $attempts Filled by get(), redeliver() and sendTest(); empty in lists.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $event,
        public readonly ?WebhookObjectType $objectType,
        public readonly ?string $objectId,
        /** Status of the latest delivery attempt. */
        public readonly DeliveryStatus $deliveryStatus,
        public readonly \DateTimeImmutable $createdAt,
        public readonly WebhookEvent $payload,
        public readonly array $attempts,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        $attempts = $p->has('attempts')
            ? array_map(static fn(Payload $a): DeliveryAttempt => DeliveryAttempt::fromPayload($a), $p->objects('attempts'))
            : [];

        return new self(
            $p->string('id'),
            $p->string('event'),
            $p->nullableEnum('object_type', WebhookObjectType::class, WebhookObjectType::Unknown),
            $p->nullableString('object_id'),
            $p->enum('delivery_status', DeliveryStatus::class, DeliveryStatus::Unknown),
            $p->dateTime('created_at'),
            WebhookEvent::fromEnvelope($p->map('payload')),
            $attempts,
        );
    }
}
