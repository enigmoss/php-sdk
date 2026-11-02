<?php

declare(strict_types=1);

namespace Enigmoss\Balance;

use Enigmoss\Internal\Payload;

/**
 * Business balance in one currency. Shared by all projects (projects) of the account owner.
 */
final class Balance
{
    /**
     * @internal
     */
    public function __construct(
        public readonly string $currencyCode,
        public readonly string $balance,
        /** USD equivalent at the current rate, 2 decimals; null when no rate is available. */
        public readonly ?string $balanceUsd,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self($p->string('currency_code'), $p->decimal('balance'), $p->nullableDecimal('balance_usd'));
    }
}
