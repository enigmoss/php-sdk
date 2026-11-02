<?php

declare(strict_types=1);

namespace Enigmoss\Transfer;

use Enigmoss\Exception\InvalidArgumentException;
use Enigmoss\Internal\Assert;

final class CreateTransferRequest
{
    /**
     * @param string $orderId      Your unique transfer ID.
     * @param string $amount       Decimal string with at most 8 decimals, e.g. "25.00".
     * @param string $currencyCode e.g. "USDT".
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $amount,
        public readonly string $currencyCode,
    ) {
        Assert::orderId($orderId);
        if (preg_match('/^\d{1,11}(\.\d{1,8})?$/', $amount) !== 1) {
            throw new InvalidArgumentException('amount must be a decimal string with at most 11 integer digits and 8 decimals.');
        }
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toBody(): array
    {
        return ['order_id' => $this->orderId, 'amount' => $this->amount, 'currency_code' => $this->currencyCode];
    }
}
