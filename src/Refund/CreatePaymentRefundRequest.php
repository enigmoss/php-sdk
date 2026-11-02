<?php

declare(strict_types=1);

namespace Enigmoss\Refund;

use Enigmoss\Internal\Assert;
use Enigmoss\Internal\Query;

final class CreatePaymentRefundRequest
{
    /**
     * @param string      $orderId               Your unique refund ID. One order_id - one refund, forever, even a failed one.
     * @param string      $address               Customer address to refund to (same currency and network as the payment).
     * @param string|null $amount                Decimal string; the whole paid amount when null.
     * @param bool|null   $feeDeductedFromAmount true (default): the fee comes out of the amount; false: you pay it on top.
     * @param string|null $callbackUrl           URL for refund.* webhooks; defaults to the payment's callback URL.
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $address,
        public readonly ?string $amount = null,
        public readonly ?bool $feeDeductedFromAmount = null,
        public readonly ?string $callbackUrl = null,
    ) {
        Assert::orderId($orderId);
        Assert::length($address, 'address', 10, 128);
        if ($amount !== null) {
            Assert::amount($amount);
        }
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toBody(): array
    {
        return Query::withoutNulls([
            'order_id' => $this->orderId,
            'address' => $this->address,
            'amount' => $this->amount,
            'fee_deducted_from_amount' => $this->feeDeductedFromAmount,
            'callback_url' => $this->callbackUrl,
        ]);
    }
}
