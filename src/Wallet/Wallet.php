<?php

declare(strict_types=1);

namespace Enigmoss\Wallet;

use Enigmoss\Internal\Payload;

/**
 * A static wallet: a permanent deposit address bound to your order_id. Every deposit to it is a
 * Payment with walletId set.
 */
final class Wallet
{
    /**
     * @internal Built by the SDK; use Enigmoss\Testing\Fixtures in tests.
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $orderId,
        public readonly string $currencyCode,
        public readonly string $networkCode,
        public readonly string $address,
        public readonly WalletStatus $status,
        /** Whether deposits arriving after blocking are refunded. */
        public readonly bool $refundIncoming,
        public readonly ?string $callbackUrl,
        /** Hosted page that shows the address and QR code to the customer. */
        public readonly string $paymentUrl,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            id: $p->string('id'),
            orderId: $p->nullableString('order_id'),
            currencyCode: $p->string('currency_code'),
            networkCode: $p->string('network_code'),
            address: $p->string('address'),
            status: $p->enum('status', WalletStatus::class, WalletStatus::Unknown),
            refundIncoming: $p->bool('refund_incoming'),
            callbackUrl: $p->nullableString('callback_url'),
            paymentUrl: $p->string('payment_url'),
            createdAt: $p->nullableDateTime('created_at'),
            updatedAt: $p->nullableDateTime('updated_at'),
        );
    }
}
