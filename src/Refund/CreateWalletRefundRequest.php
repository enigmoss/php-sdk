<?php

declare(strict_types=1);

namespace Enigmoss\Refund;

use Enigmoss\Internal\Assert;
use Enigmoss\Internal\Query;

final class CreateWalletRefundRequest
{
    /**
     * @param string      $orderId     Your unique refund ID.
     * @param string      $address     Address that receives everything paid to the blocked wallet.
     * @param string|null $callbackUrl URL for refund.* webhooks; defaults to the wallet's callback URL.
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $address,
        public readonly ?string $callbackUrl = null,
    ) {
        Assert::orderId($orderId);
        Assert::length($address, 'address', 10, 128);
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toBody(): array
    {
        return Query::withoutNulls([
            'order_id' => $this->orderId,
            'address' => $this->address,
            'callback_url' => $this->callbackUrl,
        ]);
    }
}
