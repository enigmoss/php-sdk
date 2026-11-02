# Catalog: payment methods, payout methods, exchange rates

Reference data. All of these work with **either key**; when both are configured the payment key is used (except the discount `PATCH`).

## Payment methods

The currencies and networks of your tariff that customers can pay with.

```php
foreach ($enigmoss->paymentMethods->list() as $method) {
    printf("%s %s enabled=%s discount=%d%%\n", $method->currencyCode, $method->networkCode ?? '-', $method->isEnabled ? 'yes' : 'no', $method->discountPercent);
}
```

`PaymentMethod`: `currencyCode`, `networkCode` (nullable), `isEnabled`, `discountPercent` (positive is a discount, negative a surcharge; `0` when unset).

### Set a discount or surcharge

**Payment key**, permission `payment_settings`. The value is -99 to 90 percent.

```php
$method = $enigmoss->paymentMethods->updateDiscount('USDT', 'tron', 5);   // 5% discount
$method = $enigmoss->paymentMethods->updateDiscount('USDT', 'tron', -2);   // 2% surcharge
```

A `NotFoundException` means the method does not exist for your tariff. A per-invoice `discountPercent` in `CreatePaymentRequest` is separate from this setting.

## Payout methods

Currencies and networks available for payouts.

```php
foreach ($enigmoss->payoutMethods->list() as $method) {
    printf("%s/%s min %s max %s fee %s + %s%%\n", $method->currencyCode, $method->networkCode, $method->minAmount, $method->maxAmount, $method->feeAmount, $method->feePercent);
}
```

`PayoutMethod`: `currencyCode`, `networkCode`, `contractAddress` (null for the native coin), `minAmount`, `maxAmount`, `feeAmount` (fixed part of the fee), `feePercent` (percent part).

Details of one currency and network, before building a payout:

```php
use Enigmoss\Payout\PayoutPriority;

$details = $enigmoss->payoutMethods->get('USDT', 'tron');

echo $details->networkName, ' ', $details->tokenStandard;   // e.g. "TRC-20"
echo $details->decimals, ' decimals, memo ', $details->isMemoAvailable ? 'supported' : 'not supported';

foreach ($details->priorities as $priorityFee) {
    echo $priorityFee->priority->value, ': ', $priorityFee->fee, "\n";   // PriorityFee
}
```

`PayoutMethodDetails`: `currencyCode`, `networkCode`, `networkName`, `tokenStandard`, `minAmount`, `maxAmount`, `decimals`, `isMemoAvailable`, `priorities` (a list of `PriorityFee` with `priority` and `fee`). A `NotFoundException` means an unknown pair.

## Exchange rates

```php
foreach ($enigmoss->exchangeRates->list(from: 'USDT') as $rate) {
    echo $rate->from, ' -> ', $rate->to, ' = ', $rate->rate, "\n";
}
```

`ExchangeRate`: `from`, `to`, `rate` (a decimal string with 18 fractional digits). Call `list()` without `from` for all rates. Rates are informational: the rate actually applied is the one on the invoice or payout.

## Check which key you have

`project->get()` returns the project, `keyType` (`KeyType::Payment`, `Payout`, `Unknown`), the `permissions` reachable with that key, and `serverTime`:

```php
$project = $enigmoss->project->get();

if (!in_array('payout_create', $project->permissions, true)) {
    // payouts are not available with this key
}
```
