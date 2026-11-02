<?php

declare(strict_types=1);

namespace Enigmoss\ExchangeRate;

use Enigmoss\Internal\Payload;

final class ExchangeRate
{
    /**
     * @internal
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        /** Decimal string with 18 fractional digits. */
        public readonly string $rate,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self($p->string('from'), $p->string('to'), $p->decimal('rate'));
    }
}
