<?php

declare(strict_types=1);

namespace Enigmoss\Payout;

use Enigmoss\Internal\Payload;

/**
 * A payout to an external address. The same object is the "data" of payout.* webhooks.
 */
final class Payout
{
    /**
     * @internal Built by the SDK; use Enigmoss\Testing\Fixtures in tests.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $orderId,
        public readonly PayoutStatus $status,
        public readonly bool $isFinal,
        /** Why the payout failed (gateway code or "fail", "system_fail", "aml_refund"...). */
        public readonly ?string $failureCode,
        public readonly ?string $amount,
        public readonly ?string $currencyCode,
        public readonly ?string $networkCode,
        public readonly string $address,
        public readonly ?string $memo,
        public readonly ?PayoutPriority $priority,
        public readonly PayoutAmount $recipient,
        public readonly PayoutCharge $charged,
        public readonly ?PayoutConversion $conversion,
        public readonly ?string $txHash,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        $conversion = $p->nullableObject('conversion');

        return new self(
            id: $p->string('id'),
            orderId: $p->string('order_id'),
            status: $p->enum('status', PayoutStatus::class, PayoutStatus::Unknown),
            isFinal: $p->bool('is_final'),
            failureCode: $p->nullableString('failure_code'),
            amount: $p->nullableDecimal('amount'),
            currencyCode: $p->nullableString('currency_code'),
            networkCode: $p->nullableString('network_code'),
            address: $p->string('address'),
            memo: $p->nullableString('memo'),
            priority: $p->nullableEnum('priority', PayoutPriority::class, PayoutPriority::Unknown),
            recipient: PayoutAmount::fromPayload($p->object('recipient')),
            charged: PayoutCharge::fromPayload($p->object('charged')),
            conversion: $conversion === null ? null : PayoutConversion::fromPayload($conversion),
            txHash: $p->nullableString('tx_hash'),
            createdAt: $p->nullableDateTime('created_at'),
            updatedAt: $p->nullableDateTime('updated_at'),
        );
    }

    /**
     * @internal
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return self::fromPayload(new Payload($data));
    }
}
