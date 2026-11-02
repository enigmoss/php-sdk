<?php

declare(strict_types=1);

namespace Enigmoss\PaymentMethod;

use Enigmoss\Internal\Payload;

/**
 * A currency and network the customer can pay with, and your discount for it.
 */
final class PaymentMethod
{
    /**
     * @internal
     */
    public function __construct(
        public readonly string $currencyCode,
        public readonly ?string $networkCode,
        public readonly bool $isEnabled,
        /** Positive is a discount, negative a surcharge; 0 when unset. */
        public readonly int $discountPercent,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self($p->string('currency_code'), $p->nullableString('network_code'), $p->bool('is_enabled'), $p->int('discount_percent'));
    }
}
