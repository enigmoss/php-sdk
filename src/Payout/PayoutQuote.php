<?php

declare(strict_types=1);

namespace Enigmoss\Payout;

use Enigmoss\Internal\Payload;

/**
 * Calculation of a payout without creating it: fee, debited amount, received amount.
 */
final class PayoutQuote
{
    /**
     * @internal
     */
    public function __construct(
        public readonly ?string $amount,
        public readonly string $currencyCode,
        public readonly string $networkCode,
        public readonly PayoutPriority $priority,
        public readonly PayoutAmount $recipient,
        public readonly PayoutCharge $charged,
        public readonly ?PayoutConversion $conversion,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        $conversion = $p->nullableObject('conversion');

        return new self(
            $p->nullableDecimal('amount'),
            $p->string('currency_code'),
            $p->string('network_code'),
            $p->enum('priority', PayoutPriority::class, PayoutPriority::Unknown),
            PayoutAmount::fromPayload($p->object('recipient')),
            PayoutCharge::fromPayload($p->object('charged')),
            $conversion === null ? null : PayoutConversion::fromPayload($conversion),
        );
    }
}
