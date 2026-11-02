<?php

declare(strict_types=1);

namespace Enigmoss\Payment;

use Enigmoss\Internal\Payload;

final class PaymentDiscount
{
    /**
     * @internal
     */
    public function __construct(
        /** Positive is a discount, negative a surcharge. */
        public readonly ?int $percent,
        public readonly ?string $amount,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self($p->nullableInt('percent'), $p->nullableDecimal('amount'));
    }
}
