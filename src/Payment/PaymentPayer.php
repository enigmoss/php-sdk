<?php

declare(strict_types=1);

namespace Enigmoss\Payment;

use Enigmoss\Internal\Payload;

/**
 * What the customer pays: amount, currency and network, the deposit address. Empty until the
 * customer picks a currency on the payment page.
 */
final class PaymentPayer
{
    /**
     * @internal
     */
    public function __construct(
        public readonly ?string $amount,
        public readonly ?string $currencyCode,
        public readonly ?string $networkCode,
        /** Deposit address the customer pays to. */
        public readonly ?string $address,
        /** Address the customer paid from, when known. */
        public readonly ?string $senderAddress,
        public readonly ?string $exchangeRate,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            $p->nullableDecimal('amount'),
            $p->nullableString('currency_code'),
            $p->nullableString('network_code'),
            $p->nullableString('address'),
            $p->nullableString('sender_address'),
            $p->nullableDecimal('exchange_rate'),
        );
    }
}
