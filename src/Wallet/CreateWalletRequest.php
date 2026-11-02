<?php

declare(strict_types=1);

namespace Enigmoss\Wallet;

use Enigmoss\Internal\Assert;
use Enigmoss\Internal\Query;

final class CreateWalletRequest
{
    /**
     * @param string      $currencyCode e.g. "USDT".
     * @param string      $networkCode  e.g. "tron".
     * @param string      $orderId      Your ID of the wallet owner (customer, account). One order_id - one wallet, forever.
     * @param string|null $callbackUrl  Public URL for payment.* webhooks of the wallet deposits.
     */
    public function __construct(
        public readonly string $currencyCode,
        public readonly string $networkCode,
        public readonly string $orderId,
        public readonly ?string $callbackUrl = null,
        public readonly ?string $referralCode = null,
    ) {
        Assert::orderId($orderId);
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toBody(): array
    {
        return Query::withoutNulls([
            'currency_code' => $this->currencyCode,
            'network_code' => $this->networkCode,
            'order_id' => $this->orderId,
            'callback_url' => $this->callbackUrl,
            'referral_code' => $this->referralCode,
        ]);
    }
}
