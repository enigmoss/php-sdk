<?php

declare(strict_types=1);

namespace Enigmoss\PayoutMethod;

use Enigmoss\Internal\Payload;

/**
 * A currency and network available for payouts, with limits and the base fee.
 */
final class PayoutMethod
{
    /**
     * @internal
     */
    public function __construct(
        public readonly string $currencyCode,
        public readonly string $networkCode,
        /** Token contract; null for the native coin of the network. */
        public readonly ?string $contractAddress,
        public readonly string $minAmount,
        public readonly string $maxAmount,
        /** Fixed part of the fee. */
        public readonly string $feeAmount,
        /** Percent part of the fee. */
        public readonly string $feePercent,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        $fee = $p->object('fee');

        return new self(
            $p->string('currency_code'),
            $p->string('network_code'),
            $p->nullableString('contract_address'),
            $p->decimal('min_amount'),
            $p->decimal('max_amount'),
            $fee->decimal('amount'),
            $fee->decimal('percent'),
        );
    }
}
