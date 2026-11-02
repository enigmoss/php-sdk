<?php

declare(strict_types=1);

namespace Enigmoss\Common;

/**
 * A currency, optionally on one network, for allowed_methods / excluded_methods of an invoice.
 * Without a network the rule applies to every network of the currency.
 */
final class PaymentMethodRef
{
    public function __construct(
        public readonly string $currencyCode,
        public readonly ?string $networkCode = null,
    ) {}

    /**
     * @return array{currency_code: string, network_code?: string}
     */
    public function toArray(): array
    {
        $data = ['currency_code' => $this->currencyCode];
        if ($this->networkCode !== null) {
            $data['network_code'] = $this->networkCode;
        }

        return $data;
    }
}
