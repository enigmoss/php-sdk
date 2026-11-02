<?php

declare(strict_types=1);

namespace Enigmoss\Payout;

use Enigmoss\Internal\Assert;
use Enigmoss\Internal\Query;

/**
 * Parameters of a payout.
 *
 *     new CreatePayoutRequest(orderId: 'withdrawal-42', amount: '100.00', currencyCode: 'USDT', networkCode: 'tron', address: 'T...');
 */
final class CreatePayoutRequest
{
    /**
     * @param string           $orderId               Your unique payout ID. One order_id - one payout, forever, even a failed one.
     * @param string           $amount                Decimal string, e.g. "100.00". Never a float.
     * @param string           $currencyCode          Currency to send.
     * @param string           $address               Recipient address.
     * @param string|null      $networkCode           Required when the currency has several networks.
     * @param string|null      $memo                  Memo/tag for networks that need it (up to 64 characters).
     * @param string|null      $recipientCurrencyCode Currency the recipient gets, when it differs.
     * @param string|null      $sourceCurrencyCode    Balance to fund the payout from, when it differs.
     * @param bool|null        $feeDeductedFromAmount true (default): the fee comes out of the amount.
     * @param string|null      $callbackUrl           Public URL for payout.* webhooks (up to 150 characters).
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $amount,
        public readonly string $currencyCode,
        public readonly string $address,
        public readonly ?string $networkCode = null,
        public readonly ?string $memo = null,
        public readonly ?string $recipientCurrencyCode = null,
        public readonly ?string $sourceCurrencyCode = null,
        public readonly ?bool $feeDeductedFromAmount = null,
        public readonly ?RateSource $rateSource = null,
        public readonly ?PayoutPriority $priority = null,
        public readonly ?string $callbackUrl = null,
    ) {
        Assert::orderId($orderId);
        Assert::amount($amount);
        Assert::length($address, 'address', 10, 128);
        Assert::length($memo, 'memo', 0, 64);
        Assert::length($callbackUrl, 'callbackUrl', 6, 150);
        Assert::knownEnum($priority, 'priority');
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
            'amount' => $this->amount,
            'currency_code' => $this->currencyCode,
            'network_code' => $this->networkCode,
            'address' => $this->address,
            'memo' => $this->memo,
            'recipient_currency_code' => $this->recipientCurrencyCode,
            'source_currency_code' => $this->sourceCurrencyCode,
            'fee_deducted_from_amount' => $this->feeDeductedFromAmount,
            'rate_source' => $this->rateSource?->value,
            'priority' => $this->priority?->value,
            'callback_url' => $this->callbackUrl,
        ]);
    }
}
