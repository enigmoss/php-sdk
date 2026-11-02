<?php

declare(strict_types=1);

namespace Enigmoss\Payment;

use Enigmoss\Internal\Payload;

/**
 * An invoice, or a deposit to a static wallet (then $walletId is set).
 *
 * The same object is the "data" of payment.* webhooks.
 */
final class Payment
{
    /**
     * @internal Built by the SDK; use Enigmoss\Testing\Fixtures in tests.
     *
     * @param array<array-key, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $orderId,
        public readonly ?string $walletId,
        public readonly PaymentStatus $status,
        /** True when the status will not change any more. */
        public readonly bool $isFinal,
        public readonly ?PaymentFailureCode $failureCode,
        /** Invoice amount in $currencyCode. */
        public readonly ?string $amount,
        public readonly string $currencyCode,
        public readonly PaymentPayer $payer,
        public readonly PaymentPaid $paid,
        public readonly PaymentSettlement $settlement,
        public readonly ?PaymentConversion $conversion,
        public readonly PaymentDiscount $discount,
        public readonly ?string $txHash,
        /** Hosted payment page to redirect the customer to. */
        public readonly ?string $paymentUrl,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $updatedAt,
        public readonly ?\DateTimeImmutable $paidAt,
        public readonly ?string $description,
        /** Your metadata, returned unchanged. */
        public readonly array $metadata,
    ) {}

    /**
     * The canonical fulfilment rule: the customer paid at least the invoice amount and the
     * status will not change any more. A paid payment can still be non-final while an automatic
     * conversion of the funds settles; fulfil when this returns true.
     */
    public function isSuccessful(): bool
    {
        return $this->status->isPaid() && $this->isFinal;
    }

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        $conversion = $p->nullableObject('conversion');

        return new self(
            id: $p->string('id'),
            orderId: $p->nullableString('order_id'),
            walletId: $p->nullableString('wallet_id'),
            status: $p->enum('status', PaymentStatus::class, PaymentStatus::Unknown),
            isFinal: $p->bool('is_final'),
            failureCode: $p->nullableEnum('failure_code', PaymentFailureCode::class, PaymentFailureCode::Unknown),
            amount: $p->nullableDecimal('amount'),
            currencyCode: $p->string('currency_code'),
            payer: PaymentPayer::fromPayload($p->object('payer')),
            paid: PaymentPaid::fromPayload($p->object('paid')),
            settlement: PaymentSettlement::fromPayload($p->object('settlement')),
            conversion: $conversion === null ? null : PaymentConversion::fromPayload($conversion),
            discount: PaymentDiscount::fromPayload($p->object('discount')),
            txHash: $p->nullableString('tx_hash'),
            paymentUrl: $p->nullableString('payment_url'),
            expiresAt: $p->nullableDateTime('expires_at'),
            createdAt: $p->nullableDateTime('created_at'),
            updatedAt: $p->nullableDateTime('updated_at'),
            paidAt: $p->nullableDateTime('paid_at'),
            description: $p->nullableString('description'),
            metadata: $p->map('metadata'),
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
