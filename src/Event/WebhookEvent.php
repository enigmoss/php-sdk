<?php

declare(strict_types=1);

namespace Enigmoss\Event;

use Enigmoss\Internal\Payload;

/**
 * A webhook event: {"event": "<object>.<status>", "event_id", "created_at", "data"}.
 *
 * Match on the subclass (PaymentEvent, PayoutEvent, RefundEvent, TestEvent, UnknownEvent).
 * The typed object is built lazily on first access, so an event of an unknown type or with an
 * unexpected field never breaks your endpoint: acknowledge it and inspect data().
 *
 * Deduplicate by $id: retries and redeliveries carry the same id. Events may arrive out of
 * order; compare $createdAt or fetch the object when the order matters.
 */
abstract class WebhookEvent
{
    /**
     * @param array<array-key, mixed>|null $data
     */
    final protected function __construct(
        /** event_id: stable across retries and redeliveries. */
        public readonly string $id,
        /** e.g. "payment.paid", "payout.completed", "webhook.test". */
        public readonly string $type,
        public readonly ?\DateTimeImmutable $createdAt,
        private readonly ?array $data,
    ) {}

    /**
     * Builds the event of a decoded webhook body. Never throws on unknown types.
     *
     * @internal
     *
     * @param array<array-key, mixed> $envelope
     */
    public static function fromEnvelope(array $envelope): self
    {
        $type = \is_string($envelope['event'] ?? null) ? $envelope['event'] : '';
        $id = \is_string($envelope['event_id'] ?? null) ? $envelope['event_id'] : '';
        $data = \is_array($envelope['data'] ?? null) ? $envelope['data'] : null;
        $createdAt = null;
        if (\is_string($envelope['created_at'] ?? null)) {
            try {
                $createdAt = (new \DateTimeImmutable($envelope['created_at']))->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception) {
                $createdAt = null;
            }
        }

        $class = match (true) {
            $type === 'webhook.test' => TestEvent::class,
            str_starts_with($type, 'payment.') && $data !== null => PaymentEvent::class,
            str_starts_with($type, 'payout.') && $data !== null => PayoutEvent::class,
            str_starts_with($type, 'refund.') && $data !== null => RefundEvent::class,
            default => UnknownEvent::class,
        };

        return new $class($id, $type, $createdAt, $data);
    }

    public function objectType(): WebhookObjectType
    {
        $prefix = strstr($this->type, '.', true);

        return WebhookObjectType::tryFrom($prefix === false ? $this->type : $prefix) ?? WebhookObjectType::Unknown;
    }

    /**
     * The status part of the event type ("paid" of "payment.paid").
     */
    public function statusName(): string
    {
        $dot = strpos($this->type, '.');

        return $dot === false ? '' : substr($this->type, $dot + 1);
    }

    /**
     * The raw "data" object exactly as received; null for webhook.test.
     *
     * @return array<array-key, mixed>|null
     */
    public function data(): ?array
    {
        return $this->data;
    }

    protected function payload(): Payload
    {
        return new Payload($this->data ?? [], 'data');
    }
}
