<?php

declare(strict_types=1);

namespace Enigmoss\Payment;

use Enigmoss\Internal\Payload;

final class PaymentPaid
{
    /**
     * @internal
     */
    public function __construct(
        /** Amount received, in the payer currency. */
        public readonly ?string $amount,
        /** The same amount in USD, 2 decimals. */
        public readonly ?string $amountUsd,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self($p->nullableDecimal('amount'), $p->nullableDecimal('amount_usd'));
    }
}
