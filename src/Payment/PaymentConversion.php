<?php

declare(strict_types=1);

namespace Enigmoss\Payment;

use Enigmoss\Internal\Payload;

/**
 * Automatic conversion of the received funds.
 */
final class PaymentConversion
{
    /**
     * @internal
     */
    public function __construct(
        public readonly ?string $fromAmount,
        public readonly ?string $fromCurrencyCode,
        public readonly ?string $fromNetworkCode,
        public readonly ?string $toCurrencyCode,
        public readonly ?string $rate,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        $from = $p->object('from');
        $to = $p->object('to');

        return new self(
            $from->nullableDecimal('amount'),
            $from->nullableString('currency_code'),
            $from->nullableString('network_code'),
            $to->nullableString('currency_code'),
            $p->nullableDecimal('rate'),
        );
    }
}
