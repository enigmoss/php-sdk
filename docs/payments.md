# Payments

Invoices and everything around them. Uses the **payment key**; see [Authentication and keys](authentication-and-keys.md) for permissions.

```php
$enigmoss->payments;   // Enigmoss\Payment\PaymentService
```

## Create an invoice

```php
use Enigmoss\Common\PaymentMethodRef;
use Enigmoss\Payment\CreatePaymentRequest;
use Enigmoss\Payment\Theme;

$payment = $enigmoss->payments->create(new CreatePaymentRequest(
    amount: '10.50',
    currencyCode: 'USDT',
    orderId: 'order-42',
    returnUrl: 'https://shop.example/cart',
    successUrl: 'https://shop.example/thanks',
    callbackUrl: 'https://shop.example/webhooks/enigmoss',
    expiresIn: 3600,
    description: 'Order 42',
    metadata: ['customer_id' => 42],
    allowedMethods: [new PaymentMethodRef('USDT', 'tron'), new PaymentMethodRef('BTC')],
    theme: Theme::Dark,
));

if ($payment->paymentUrl === null) {
    // may be null: do not redirect to an empty URL; reconcile with payments->get($payment->id)
}

echo $payment->paymentUrl;   // redirect the customer here
```

Only `amount`, `currencyCode` and `orderId` are required.

| Parameter | Notes |
|---|---|
| `amount` | Decimal string in `currencyCode`, e.g. `"10.50"`. Never a float. Up to 11 integer and 18 fractional digits. |
| `currencyCode` | Invoice currency, e.g. `"USDT"` or `"USD"`. |
| `orderId` | Your unique ID, 1-128 letters, digits, `-` or `_`. **One `order_id` is one invoice, forever.** |
| `settlementCurrencyCode`, `networkCode` | Fix the currency/network the customer pays in. |
| `returnUrl`, `successUrl` | "Back to shop" link and post-payment redirect. |
| `callbackUrl` | Public URL for the `payment.*` webhooks of this invoice. |
| `expiresIn` | Lifetime in seconds, 300-43200 (default 3600). |
| `allowMultiplePayments` | Accept several payments to one invoice. |
| `customerFeePercent` | Share of the fee paid by the customer, 0-100. |
| `underpaymentTolerancePercent` | Decimal string `"0"` to `"5.00"`: an underpayment within this share still counts as paid. |
| `discountPercent` | -99 to 90; positive is a discount, negative a surcharge. |
| `allowedMethods`, `excludedMethods` | Lists of `PaymentMethodRef(currencyCode, networkCode?)`. Without a network the rule covers every network of the currency. |
| `customerEmail`, `description` (max 150), `referralCode` | Optional text. |
| `metadata` | A JSON **object** (associative array or `stdClass`, not a list). Returned unchanged in the payment and in webhooks. |
| `theme` | `Theme::Light`, `Dark`, `System`. |

`create()` is safe to repeat. The default `Idempotency-Key` is derived from the `order_id`, so repeating the same request (a retried job, a double click) returns the same invoice instead of a conflict, for 24 hours. See [Idempotency and retries](idempotency-and-retries.md).

Errors: see [Errors of create](#errors-of-create).

## Errors of create

| HTTP | `errorCode` | Exception | Outcome | What to do |
|---|---|---|---|---|
| 422 | `validation.failed` | `ValidationException` | Not created | See `violations`; repeat with the same `order_id` after the fix. |
| 422 | amount limits, unavailable currency or network (`domain.rule_violation` or a specific code) | `BusinessRuleException` | Not created | Read `errorCode` and `message`; change amount, currency or network. |
| 409 | `resource.conflict` with `details.id` | `ConflictException`, `isOrderIdTaken()` is `true` | Created earlier | `payments->get($e->existingResourceId())`. Do not create a new `order_id` for the same order. |
| 409 | `idempotency.payload_mismatch` | `IdempotencyException` | Not created | The `order_id` was sent before with different data. |
| 409 | `idempotency.in_progress` | `IdempotencyException` | **Unknown** | Look it up with `findByOrderId()` later. |
| 403 | `api_permission.denied` | `PermissionDeniedException` | Not created | Enable `payment_create_invoice`. |
| 429 | `rate_limit.exceeded` | `RateLimitException` | Not created | Wait `$retryAfter`. |
| 5xx, none | `dependency.*`, `internal.*`, no response | `DependencyException`, `ServerException`, `TransportException` | **Unknown** | `findByOrderId()` before creating again. |

The shared rows are in [Errors](errors.md#rows-every-create-endpoint-can-return). Check `outcomeUnknown()` first, then `isOrderIdTaken()` ([the catch order](errors.md#the-catch-order)).

## The Payment object

`Enigmoss\Payment\Payment` is also the `data` of `payment.*` webhooks. It represents an invoice or a **deposit to a static wallet** (then `walletId` is set).

| Property | Type | Notes |
|---|---|---|
| `id` | `string` | Invoice UUID. |
| `orderId` | `?string` | Your order ID; `null` for wallet deposits (then `walletId` is set). |
| `walletId` | `?string` | Set for deposits to a static wallet; `null` for invoices. |
| `status` | `PaymentStatus` | See below. |
| `isFinal` | `bool` | True when the status will not change any more. |
| `failureCode` | `?PaymentFailureCode` | `PaymentFailed`, `ProcessingError`, `Unknown`. May be null (typically when the payment did not fail); handle it. |
| `amount` | `?string` | Invoice amount. May be null; handle it. |
| `currencyCode` | `string` | Invoice currency. |
| `payer` | `PaymentPayer` | What the customer pays: `amount`, `currencyCode`, `networkCode`, deposit `address`, `senderAddress`, `exchangeRate`. Empty until the customer picks a currency. |
| `paid` | `PaymentPaid` | Received `amount` and its `amountUsd`. |
| `settlement` | `PaymentSettlement` | Credited `amount`, `currencyCode` and `fee`. |
| `conversion` | `?PaymentConversion` | Automatic conversion of received funds. May be null; handle it. |
| `discount` | `PaymentDiscount` | `percent` and `amount`. |
| `txHash`, `paymentUrl`, `description` | `?string` | `paymentUrl` is the hosted page to redirect to. May be null: check before redirecting. `txHash` and `description` may be null. |
| `expiresAt`, `createdAt`, `updatedAt`, `paidAt` | `?DateTimeImmutable` | UTC. May be null; handle it. |
| `metadata` | `array` | Your metadata, unchanged. |

### Statuses and the fulfilment rule

`PaymentStatus` cases: `Pending`, `Processing`, `Confirming`, `Paid`, `Overpaid`, `Underpaid`, `UnderpaidWaiting`, `Expired`, `Cancelled`, `Failed`, `Locked`, `Refunding`, `Refunded`, `RefundFailed`, `Unknown`.

**The one rule to fulfil an order: `$payment->isSuccessful()`.** It is `status->isPaid() && isFinal`. `isPaid()` is true for `Paid` and `Overpaid` (the customer paid at least the invoice amount). A paid payment can still be **non-final** while an automatic conversion of the funds settles, so fulfil when `isSuccessful()` is true, not on `isPaid()` alone. Use this rule everywhere: webhook handlers, reconcile jobs and return pages.

```php
if ($payment->isSuccessful()) {
    // fulfil $payment->orderId (idempotently)
}
```

`isFinal` comes from the server. The table is derived from the meaning of the statuses; where it says "typically", trust `$payment->isFinal` rather than the table.

| Status | `isFinal` | What the shop should do | Typical next statuses |
|---|---|---|---|
| `Pending` | never | Show "waiting for payment". | `Processing`, `Confirming`, `Paid`, `Expired`, `Cancelled` |
| `Processing` | never | Wait. | `Confirming`, `Paid`, `Underpaid`, `Failed` |
| `Confirming` | never | Funds were seen, waiting for confirmations. Wait. | `Paid`, `Overpaid`, `Underpaid`, `Failed` |
| `Paid` | may be false (conversion still settling) | Fulfil when `isSuccessful()`; otherwise wait for the next webhook. | `Refunding` |
| `Overpaid` | may be false | As `Paid`. Decide yourself what to do with the surplus. | `Refunding` |
| `Underpaid` | may be false | Not fully paid: do not fulfil. Ask for the rest, or accept it with `markPaid()` (see below). | `UnderpaidWaiting`, `Paid`, `Refunding` |
| `UnderpaidWaiting` | never | Waiting for the missing part. Do not fulfil. | `Paid`, `Underpaid`, `Expired` |
| `Expired` | always (typically) | Not paid. Offer a new invoice with a new `order_id` ([see below](#retrying-payment-for-an-order)). | none |
| `Cancelled` | always (typically) | Not paid. Same as `Expired`. | none |
| `Failed` | always (typically) | Not paid; read `failureCode`. | none |
| `Locked` | never | An AML check holds the funds. Do not fulfil; send the customer to the verification link ([AML links](#aml-verification-links)). | `Paid`, `Refunding`, `Failed` |
| `Refunding` | never | A refund is in progress. | `Refunded`, `RefundFailed` |
| `Refunded` | always (typically) | Mark the order refunded. | none |
| `RefundFailed` | typically final | The refund failed: check with the API, handle manually. | none |
| `Unknown` | read `isFinal` | The SDK does not know this status. Do not fulfil; log it. | |

New statuses may be added in minor releases and arrive as `Unknown` in an older SDK. Always keep a default branch:

```php
use Enigmoss\Payment\PaymentStatus;

$message = match ($payment->status) {
    PaymentStatus::Paid, PaymentStatus::Overpaid => 'Thank you!',
    PaymentStatus::Expired, PaymentStatus::Cancelled, PaymentStatus::Failed => 'Payment did not complete.',
    default => 'Waiting for payment...',
};
```

## Read invoices

```php
$payment = $enigmoss->payments->get('7c9e6679-7425-40de-944b-e07fc1f90ae7');   // NotFoundException if unknown

$byOrder = $enigmoss->payments->findByOrderId('order-42');   // ?Payment
```

`findByOrderId()` is the reconciliation tool after an error whose `outcomeUnknown()` is true. It does not find static wallet deposits (they have no `order_id`): list by `walletId` instead.

## List and filter

```php
use Enigmoss\Payment\ListPaymentsQuery;
use Enigmoss\Payment\PaymentStatus;

$page = $enigmoss->payments->list(new ListPaymentsQuery(
    status: PaymentStatus::Paid,
    createdFrom: new DateTimeImmutable('-7 days'),
    limit: 50,
));

foreach ($enigmoss->payments->iterate(new ListPaymentsQuery(walletId: $walletId)) as $deposit) {
    // every deposit, pages fetched lazily
}
```

Filters: `orderId`, `walletId`, `status`, `createdFrom`, `createdTo`, `limit` (1-50, default 20), `cursor`. Newest first. See [Pagination](pagination.md).

## Cancel

Cancels an unpaid invoice (status `pending` or `processing`, nothing received yet). Permission `payment_cancel`.

```php
use Enigmoss\Exception\ConflictException;

try {
    $payment = $enigmoss->payments->cancel($payment->id);
} catch (ConflictException $e) {
    // The invoice can no longer be cancelled (for example it was paid in the meantime).
}
```

## Mark an underpaid invoice as paid

Accepts an underpaid invoice as paid. Permission `payment_mark_paid`. For `UnderpaidWaiting` the call asks the gateway to close the invoice and the response may still be `UnderpaidWaiting`: wait for the `payment.*` webhook rather than assuming success.

```php
$payment = $enigmoss->payments->markPaid($payment->id);
```

## AML verification links

When an incoming payment is `Locked` by an AML check, the customer can pass verification at a link. `createAmlVerificationLinks()` returns the active link(s) and creates one if none is active. It needs the permission `info_payments`.

```php
$links = $enigmoss->payments->createAmlVerificationLinks($payment->id);   // list<AmlVerificationLink>

foreach ($links as $link) {
    echo $link->url, ' ', $link->status?->value, ' until ', $link->expiresAt?->format(DATE_ATOM), "\n";
}
```

`AmlVerificationLinkStatus`: `Init`, `Pending`, `Completed`, `Unknown`. A `ConflictException` means the payment is not locked or verification already started.

## Retrying payment for an order

An `order_id` is one invoice forever. When the invoice ended in `Expired` or `Cancelled` and the customer wants to pay again, **create a new invoice with a new `order_id`**, for example `<order>-<attempt>`:

```php
$attempt = $order->nextPaymentAttempt();   // 1, 2, 3... stored with your order

$payment = $enigmoss->payments->create(new CreatePaymentRequest(
    amount: '10.50',
    currencyCode: 'USDT',
    orderId: $order->id . '-' . $attempt,   // order-42-2
));
```

Rules that follow from this:

- Repeating the **same** create (a retried job, a double click) returns the stored response for **24 hours**. After that the same `order_id` gives a `ConflictException` with `isOrderIdTaken()` and the existing invoice in `existingResourceId()`. Do not rely on either to "reopen" an invoice.
- Map the attempt back to your order (strip the suffix, or store the pair in your database). The webhook's `orderId` is the attempt's `order_id`.
- Always decide by the **webhook status** (and `isSuccessful()`), not by which attempt the customer opened. Two invoices of one order can both end up paid; your handler must fulfil the order once and flag the second payment for manual handling or a refund.
- What happens to a payment that arrives at an invoice after it expired is decided by the API, not by the SDK: the docs do not promise either behaviour, so handle both (a webhook may still arrive for an `Expired` invoice).

## Follow a payment

Do not poll. Use [webhooks](webhooks.md) for status changes and call `payments->get()` or `findByOrderId()` for reconciliation. If you missed webhooks, replay them from the [events journal](webhook-events-api.md).
