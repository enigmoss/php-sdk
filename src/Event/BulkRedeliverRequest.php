<?php

declare(strict_types=1);

namespace Enigmoss\Event;

use Enigmoss\Exception\InvalidArgumentException;
use Enigmoss\Internal\Assert;
use Enigmoss\Internal\Query;

/**
 * Selects events to redeliver: the latest event of each matching object, at most 1000.
 */
final class BulkRedeliverRequest
{
    /**
     * @param \DateTimeInterface $createdFrom Required; not older than 60 days.
     */
    public function __construct(
        public readonly \DateTimeInterface $createdFrom,
        public readonly ?\DateTimeInterface $createdTo = null,
        public readonly ?string $event = null,
        public readonly ?WebhookObjectType $objectType = null,
        public readonly ?string $objectId = null,
        public readonly ?DeliveryStatus $deliveryStatus = null,
    ) {
        if ($event !== null && preg_match('/^[a-z_]+\.[a-z_]+$/', $event) !== 1) {
            throw new InvalidArgumentException('event must look like "payment.paid".');
        }
        if ($objectId !== null) {
            Assert::uuid($objectId, 'objectId');
        }
        Assert::knownEnum($objectType, 'objectType');
        Assert::knownEnum($deliveryStatus, 'deliveryStatus');
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toBody(): array
    {
        return Query::withoutNulls([
            'created_from' => Query::dateTime($this->createdFrom),
            'created_to' => Query::dateTime($this->createdTo),
            'event' => $this->event,
            'object_type' => $this->objectType?->value,
            'object_id' => $this->objectId,
            'delivery_status' => $this->deliveryStatus?->value,
        ]);
    }
}
