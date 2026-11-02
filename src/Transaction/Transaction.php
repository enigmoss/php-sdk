<?php

declare(strict_types=1);

namespace Enigmoss\Transaction;

use Enigmoss\Internal\Payload;

/**
 * One balance movement of the project. The *Id links are set for objects of API v4 only.
 */
final class Transaction
{
    /**
     * @internal
     */
    public function __construct(
        public readonly string $id,
        public readonly TransactionType $type,
        public readonly string $amount,
        public readonly string $fee,
        public readonly string $currencyCode,
        public readonly ?string $networkCode,
        public readonly ?string $paymentId,
        public readonly ?string $payoutId,
        public readonly ?string $refundId,
        public readonly ?string $transferId,
        public readonly \DateTimeImmutable $createdAt,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            id: $p->string('id'),
            type: $p->enum('type', TransactionType::class, TransactionType::Unknown),
            amount: $p->decimal('amount'),
            fee: $p->decimal('fee'),
            currencyCode: $p->string('currency_code'),
            networkCode: $p->nullableString('network_code'),
            paymentId: $p->nullableString('payment_id'),
            payoutId: $p->nullableString('payout_id'),
            refundId: $p->nullableString('refund_id'),
            transferId: $p->nullableString('transfer_id'),
            createdAt: $p->dateTime('created_at'),
        );
    }
}
