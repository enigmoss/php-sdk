<?php

declare(strict_types=1);

namespace Enigmoss\Payout;

use Enigmoss\Internal\Payload;

/**
 * What the recipient address receives.
 */
final class PayoutAmount
{
    /**
     * @internal
     */
    public function __construct(
        public readonly ?string $amount,
        public readonly ?string $currencyCode,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self($p->nullableDecimal('amount'), $p->nullableString('currency_code'));
    }
}
