<?php

declare(strict_types=1);

namespace Enigmoss\Payment;

use Enigmoss\Common\PaymentMethodRef;
use Enigmoss\Exception\InvalidArgumentException;
use Enigmoss\Internal\Assert;
use Enigmoss\Internal\Query;

/**
 * Parameters of a new invoice. Only amount, currencyCode and orderId are required.
 *
 *     new CreatePaymentRequest(amount: '10.50', currencyCode: 'USDT', orderId: 'order-42', callbackUrl: 'https://shop.example/webhooks/enigmoss');
 */
final class CreatePaymentRequest
{
    /**
     * @param string                       $amount                        Decimal string in $currencyCode, e.g. "10.50". Never a float.
     * @param string                       $currencyCode                  Invoice currency, e.g. "USDT" or "USD".
     * @param string                       $orderId                       Your unique order ID (1-128 letters, digits, "-", "_"). One order_id - one invoice, forever.
     * @param string|null                  $settlementCurrencyCode        Currency the customer pays in, when you want to fix it.
     * @param string|null                  $networkCode                   Network the customer pays on, e.g. "tron".
     * @param string|null                  $returnUrl                     "Back to shop" link on the payment page.
     * @param string|null                  $successUrl                    Where the customer goes after paying.
     * @param string|null                  $callbackUrl                   Public URL for payment.* webhooks of this invoice.
     * @param int|null                     $expiresIn                     Lifetime in seconds, 300-43200 (default 3600).
     * @param bool|null                    $allowMultiplePayments         Accept several payments to the invoice.
     * @param int|null                     $customerFeePercent            Share of the fee paid by the customer, 0-100.
     * @param string|null                  $underpaymentTolerancePercent  Underpayment still treated as paid, "0"-"5.00".
     * @param int|null                     $discountPercent               -99..90; positive is a discount, negative a surcharge.
     * @param list<PaymentMethodRef>|null  $allowedMethods                Only these currencies/networks are offered.
     * @param list<PaymentMethodRef>|null  $excludedMethods               These currencies/networks are not offered.
     * @param string|null                  $customerEmail
     * @param array<array-key, mixed>|\stdClass|null $metadata           Any JSON object, returned unchanged in the payment and webhooks.
     * @param string|null                  $description                   Up to 150 characters, shown to the customer.
     * @param Theme|null                   $theme                         Payment page theme.
     * @param string|null                  $referralCode
     */
    public function __construct(
        public readonly string $amount,
        public readonly string $currencyCode,
        public readonly string $orderId,
        public readonly ?string $settlementCurrencyCode = null,
        public readonly ?string $networkCode = null,
        public readonly ?string $returnUrl = null,
        public readonly ?string $successUrl = null,
        public readonly ?string $callbackUrl = null,
        public readonly ?int $expiresIn = null,
        public readonly ?bool $allowMultiplePayments = null,
        public readonly ?int $customerFeePercent = null,
        public readonly ?string $underpaymentTolerancePercent = null,
        public readonly ?int $discountPercent = null,
        public readonly ?array $allowedMethods = null,
        public readonly ?array $excludedMethods = null,
        public readonly ?string $customerEmail = null,
        public readonly array|\stdClass|null $metadata = null,
        public readonly ?string $description = null,
        public readonly ?Theme $theme = null,
        public readonly ?string $referralCode = null,
    ) {
        Assert::amount($amount);
        Assert::orderId($orderId);
        Assert::range($expiresIn, 'expiresIn', 300, 43200);
        Assert::range($customerFeePercent, 'customerFeePercent', 0, 100);
        Assert::range($discountPercent, 'discountPercent', -99, 90);
        Assert::length($description, 'description', 0, 150);
        if ($underpaymentTolerancePercent !== null && preg_match('/^\d+(\.\d{1,2})?$/', $underpaymentTolerancePercent) !== 1) {
            throw new InvalidArgumentException('underpaymentTolerancePercent must be a decimal string with up to 2 decimals, e.g. "1.5".');
        }
        if (\is_array($metadata) && $metadata !== [] && array_is_list($metadata)) {
            throw new InvalidArgumentException('metadata must be a JSON object (an associative array or stdClass), not a list.');
        }
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
            'order_id' => $this->orderId,
            'settlement_currency_code' => $this->settlementCurrencyCode,
            'network_code' => $this->networkCode,
            'return_url' => $this->returnUrl,
            'success_url' => $this->successUrl,
            'callback_url' => $this->callbackUrl,
            'expires_in' => $this->expiresIn,
            'allow_multiple_payments' => $this->allowMultiplePayments,
            'customer_fee_percent' => $this->customerFeePercent,
            'underpayment_tolerance_percent' => $this->underpaymentTolerancePercent,
            'discount_percent' => $this->discountPercent,
            'allowed_methods' => self::methods($this->allowedMethods),
            'excluded_methods' => self::methods($this->excludedMethods),
            'customer_email' => $this->customerEmail,
            // Always an object: an empty PHP array would otherwise be encoded as [].
            'metadata' => $this->metadata === null ? null : (object) $this->metadata,
            'description' => $this->description,
            'theme' => $this->theme?->value,
            'referral_code' => $this->referralCode,
        ]);
    }

    /**
     * @param list<PaymentMethodRef>|null $refs
     *
     * @return list<array{currency_code: string, network_code?: string}>|null
     */
    private static function methods(?array $refs): ?array
    {
        if ($refs === null) {
            return null;
        }

        return array_map(static fn(PaymentMethodRef $ref): array => $ref->toArray(), $refs);
    }
}
