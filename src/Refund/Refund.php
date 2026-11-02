<?php

declare(strict_types=1);

namespace Enigmoss\Refund;

use Enigmoss\Internal\Payload;

/**
 * A refund of a payment or of a blocked static wallet. The same object is the "data" of
 * refund.* webhooks.
 */
final class Refund
{
    /**
     * @internal Built by the SDK; use Enigmoss\Testing\Fixtures in tests.
     */
    public function __construct(
        public readonly string $id,
        /** Null only for automatic refunds of a blocked wallet. */
        public readonly ?string $orderId,
        public readonly RefundSource $source,
        public readonly ?string $paymentId,
        public readonly ?string $walletId,
        public readonly RefundStatus $status,
        public readonly bool $isFinal,
        public readonly ?RefundFailureCode $failureCode,
        public readonly ?string $amount,
        public readonly string $currencyCode,
        public readonly string $networkCode,
        public readonly string $address,
        public readonly ?string $txHash,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $updatedAt,
        public readonly ?\DateTimeImmutable $completedAt,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            id: $p->string('id'),
            orderId: $p->nullableString('order_id'),
            source: $p->enum('source', RefundSource::class, RefundSource::Unknown),
            paymentId: $p->nullableString('payment_id'),
            walletId: $p->nullableString('wallet_id'),
            status: $p->enum('status', RefundStatus::class, RefundStatus::Unknown),
            isFinal: $p->bool('is_final'),
            failureCode: $p->nullableEnum('failure_code', RefundFailureCode::class, RefundFailureCode::Unknown),
            amount: $p->nullableDecimal('amount'),
            currencyCode: $p->string('currency_code'),
            networkCode: $p->string('network_code'),
            address: $p->string('address'),
            txHash: $p->nullableString('tx_hash'),
            createdAt: $p->nullableDateTime('created_at'),
            updatedAt: $p->nullableDateTime('updated_at'),
            completedAt: $p->nullableDateTime('completed_at'),
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
