<?php

declare(strict_types=1);

namespace Enigmoss\Payout;

use Enigmoss\Internal\Payload;

/**
 * Conversion from another balance used to fund the payout.
 */
final class PayoutConversion
{
    /**
     * @internal
     */
    public function __construct(
        public readonly ?string $sourceCurrencyCode,
        public readonly ?string $sourceAmount,
        public readonly ?string $fee,
        public readonly ?string $rate,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            $p->nullableString('source_currency_code'),
            $p->nullableDecimal('source_amount'),
            $p->nullableDecimal('fee'),
            $p->nullableDecimal('rate'),
        );
    }
}
