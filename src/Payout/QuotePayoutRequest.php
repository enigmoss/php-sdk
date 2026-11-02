<?php

declare(strict_types=1);

namespace Enigmoss\Payout;

use Enigmoss\Internal\Assert;
use Enigmoss\Internal\Query;

/**
 * Parameters of a payout calculation. The same fields as CreatePayoutRequest minus orderId,
 * memo and callbackUrl.
 */
final class QuotePayoutRequest
{
    /**
     * @param string             $amount                Decimal string, e.g. "100.00".
     * @param string             $currencyCode          Currency to send, e.g. "USDT".
     * @param string             $address               Recipient address.
     * @param string|null        $networkCode           Required when the currency has several networks.
     * @param string|null        $recipientCurrencyCode Currency the recipient gets, when it differs.
     * @param string|null        $sourceCurrencyCode    Balance to fund the payout from, when it differs.
     * @param bool|null          $feeDeductedFromAmount true (default): the fee comes out of the amount.
     */
    public function __construct(
        public readonly string $amount,
        public readonly string $currencyCode,
        public readonly string $address,
        public readonly ?string $networkCode = null,
        public readonly ?string $recipientCurrencyCode = null,
        public readonly ?string $sourceCurrencyCode = null,
        public readonly ?bool $feeDeductedFromAmount = null,
        public readonly ?RateSource $rateSource = null,
        public readonly ?PayoutPriority $priority = null,
    ) {
        Assert::amount($amount);
        Assert::length($address, 'address', 10, 128);
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
            'amount' => $this->amount,
            'currency_code' => $this->currencyCode,
            'network_code' => $this->networkCode,
            'address' => $this->address,
            'recipient_currency_code' => $this->recipientCurrencyCode,
            'source_currency_code' => $this->sourceCurrencyCode,
            'fee_deducted_from_amount' => $this->feeDeductedFromAmount,
            'rate_source' => $this->rateSource?->value,
            'priority' => $this->priority?->value,
        ]);
    }
}
