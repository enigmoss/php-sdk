<?php

declare(strict_types=1);

namespace Enigmoss\Payment;

use Enigmoss\Internal\Payload;

/**
 * What is credited to your balance and the fee charged.
 */
final class PaymentSettlement
{
    /**
     * @internal
     */
    public function __construct(
        public readonly ?string $amount,
        public readonly ?string $currencyCode,
        public readonly ?string $fee,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self($p->nullableDecimal('amount'), $p->nullableString('currency_code'), $p->nullableDecimal('fee'));
    }
}
