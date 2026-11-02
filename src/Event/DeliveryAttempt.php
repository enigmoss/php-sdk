<?php

declare(strict_types=1);

namespace Enigmoss\Event;

use Enigmoss\Internal\Payload;

/**
 * One delivery attempt of a webhook event, with your endpoint's response.
 */
final class DeliveryAttempt
{
    /**
     * @internal
     */
    public function __construct(
        public readonly int $number,
        public readonly AttemptTrigger $trigger,
        public readonly DeliveryStatus $status,
        public readonly ?string $url,
        public readonly ?int $responseStatusCode,
        /** First 64000 bytes of your endpoint's response. */
        public readonly ?string $responseBody,
        public readonly ?\DateTimeImmutable $scheduledAt,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            $p->int('number'),
            $p->enum('trigger', AttemptTrigger::class, AttemptTrigger::Unknown),
            $p->enum('status', DeliveryStatus::class, DeliveryStatus::Unknown),
            $p->nullableString('url'),
            $p->nullableInt('response_status_code'),
            $p->nullableString('response_body'),
            $p->nullableDateTime('scheduled_at'),
            $p->dateTime('created_at'),
            $p->nullableDateTime('updated_at'),
        );
    }
}
