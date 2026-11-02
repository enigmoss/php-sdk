<?php

declare(strict_types=1);

namespace Enigmoss\Event;

use Enigmoss\Exception\InvalidArgumentException;
use Enigmoss\Internal\Assert;
use Enigmoss\Internal\Query;

/**
 * Filters of the events journal (events are kept for 60 days).
 */
final class ListEventsQuery
{
    /**
     * @param string|null $event e.g. "payment.paid".
     * @param int|null    $limit Page size, 1-100 (default 20).
     */
    public function __construct(
        public readonly ?string $event = null,
        public readonly ?WebhookObjectType $objectType = null,
        public readonly ?string $objectId = null,
        public readonly ?DeliveryStatus $deliveryStatus = null,
        public readonly ?\DateTimeInterface $createdFrom = null,
        public readonly ?\DateTimeInterface $createdTo = null,
        public readonly ?int $limit = null,
        public readonly ?string $cursor = null,
    ) {
        if ($event !== null && preg_match('/^[a-z_]+\.[a-z_]+$/', $event) !== 1) {
            throw new InvalidArgumentException('event must look like "payment.paid".');
        }
        if ($objectId !== null) {
            Assert::uuid($objectId, 'objectId');
        }
        Assert::knownEnum($objectType, 'objectType');
        Assert::knownEnum($deliveryStatus, 'deliveryStatus');
        Assert::range($limit, 'limit', 1, 100);
    }

    public function withCursor(?string $cursor): self
    {
        return new self($this->event, $this->objectType, $this->objectId, $this->deliveryStatus, $this->createdFrom, $this->createdTo, $this->limit, $cursor);
    }

    /**
     * @internal
     *
     * @return array<string, string|int|bool|null>
     */
    public function toQuery(): array
    {
        return [
            'event' => $this->event,
            'object_type' => $this->objectType?->value,
            'object_id' => $this->objectId,
            'delivery_status' => $this->deliveryStatus?->value,
            'created_from' => Query::dateTime($this->createdFrom),
            'created_to' => Query::dateTime($this->createdTo),
            'limit' => $this->limit,
            'cursor' => $this->cursor,
        ];
    }
}
