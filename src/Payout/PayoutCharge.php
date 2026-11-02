<?php

declare(strict_types=1);

namespace Enigmoss\Payout;

use Enigmoss\Internal\Payload;

/**
 * What is debited from your balance, fee included.
 */
final class PayoutCharge
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
