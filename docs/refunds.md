# Refunds

Refund a paid invoice, or everything paid to a blocked static wallet. Uses the **payout key**: the server IP must be allowed by the project's IP whitelist, and the permission is `payment_refund`. Creating refunds is limited to **1 request per minute per project**.

```php
$enigmoss->refunds;   // Enigmoss\Refund\RefundService
```

## Refund a payment

```php
use Enigmoss\Refund\CreatePaymentRefundRequest;

$refund = $enigmoss->refunds->createForPayment($paymentId, new CreatePaymentRefundRequest(
    orderId: 'refund-42',
    address: 'TCustomerAddress...',
    amount: '5.00',
    feeDeductedFromAmount: true,
));

echo $refund->status->value;   // pending
```

| Parameter | Notes |
|---|---|
| `orderId` | Your unique refund ID. **One `order_id` is one refund, forever, even a failed one.** |
| `address` | Customer address to refund to (same currency and network as the payment). 10-128 characters. |
| `amount` | Decimal string; the whole paid amount when `null`. |
| `feeDeductedFromAmount` | `true` (default): the fee comes out of the amount; `false`: you pay it on top. |
| `callbackUrl` | URL for `refund.*` webhooks; defaults to the payment's callback URL. |

The call returns the refund in status `pending`. Follow it with `refund.*` webhooks or `refunds->get()`.

### Money-safe repeats

The `Idempotency-Key` is derived from the payment ID and the `order_id`, so repeating the call returns the stored refund. A refund create has the same three outcomes as a payout: **created**, **definitely not created** (fix the cause; the same `order_id` may be used again) and **pending reconcile** (`outcomeUnknown()` is `true`). Never create a new `order_id` for the same refund, and do not repeat the create in the same request: refund creation is slow too (the SDK waits at least 180 seconds per attempt), so the object may not be visible yet, and a second refund is real money.

Check `outcomeUnknown()` first, then `isOrderIdTaken()`:

```php
use Enigmoss\Exception\ConflictException;
use Enigmoss\Exception\EnigmossException;

try {
    $refund = $enigmoss->refunds->createForPayment($paymentId, $request);
} catch (EnigmossException $e) {
    if ($e->outcomeUnknown()) {
        // Pending reconcile (also idempotency.in_progress): keep your record "pending", stop here.
        return;
    }

    if ($e instanceof ConflictException && $e->isOrderIdTaken()) {
        $refund = $enigmoss->refunds->get((string) $e->existingResourceId());   // already created
    } else {
        throw $e;   // definitely not created (state conflict, amount, funds...)
    }
}
```

`IdempotencyException extends ConflictException`: never treat a conflict as "order_id taken" without `isOrderIdTaken()`. A state conflict ("the payment cannot be refunded now") is a `ConflictException` too, with `isOrderIdTaken()` false.

Reconcile pending refunds later, by cron or on the `refund.*` webhook (the webhook is verified with the **payment key**, see [Authentication and keys](authentication-and-keys.md#which-keys-a-scenario-needs)):

```php
$refund = $enigmoss->refunds->findByOrderId($orderId);   // ?Refund

if ($refund === null && $pendingFor > 300 /* seconds */) {
    // Still no object after a few minutes: repeating the create with the SAME order_id is safe.
}
```

### Errors of create

| HTTP | `errorCode` | Exception | Outcome | What to do |
|---|---|---|---|---|
| 409 | `resource.conflict` with `details.id` | `ConflictException`, `isOrderIdTaken()` is `true` | Created earlier | `refunds->get($e->existingResourceId())`. |
| 409 | `resource.conflict` with `details.status` or `details.reason` | `ConflictException`, `isOrderIdTaken()` is `false` | Not created | The payment (or wallet) state does not allow a refund now. Read `details`. |
| 422 | `refund.payment_not_refundable`, `refund.wallet_not_refundable` | `BusinessRuleException` | Not created | The object cannot be refunded. |
| 422 | `refund.amount_exceeds_paid`, `refund.amount_too_small` | `BusinessRuleException` | Not created | Fix `amount`; the same `order_id` may be used. |
| 422 | `refund.insufficient_funds` | `BusinessRuleException` | Not created | Top up the balance, then repeat with the same `order_id`. |
| 422 | `refund.aml_not_supported` | `BusinessRuleException` | Not created | Not available for this payment. |
| 422 | `refund.nothing_to_refund` | `BusinessRuleException` | Not created | Nothing to refund (wallet refund). |
| 403 | `refund.payouts_forbidden` | `PermissionDeniedException` | Not created | Refunds are disabled for the project. Enable payouts for the project in the dashboard. |
| 403 | `auth.ip_forbidden` | `IpNotAllowedException` | Not created | Add the server IP to the whitelist. |
| 429 | `rate_limit.exceeded` | `RateLimitException` | Not created | More than one creation per minute: wait `$retryAfter`. |
| 409 | `idempotency.in_progress` | `IdempotencyException` | **Unknown** | Pending reconcile. |
| 5xx, none | `dependency.*`, `internal.*`, no response | `DependencyException`, `ServerException`, `TransportException` | **Unknown** | Pending reconcile. |

The shared rows (authentication, validation, `payload_mismatch`, `lock.not_acquired`) are in [Errors](errors.md#rows-every-create-endpoint-can-return).

## Refund a blocked wallet

Refunds everything paid to a blocked static wallet in one payout, once per wallet.

```php
use Enigmoss\Refund\CreateWalletRefundRequest;

$wallet = $enigmoss->wallets->block($walletId, refundIncoming: false);   // payment key

$refund = $enigmoss->refunds->createForWallet($walletId, new CreateWalletRefundRequest(
    orderId: 'wallet-refund-7',
    address: 'TCustomerAddress...',
));
```

A `ConflictException` means the wallet is not blocked or was already refunded (check `isOrderIdTaken()` to tell an `order_id` that is already used from a state conflict). A `refund.nothing_to_refund` business error means there was nothing to refund. Blocking needs the **payment key** and the refund needs the **payout key**, so this scenario needs both keys.

## The Refund object

The same object is the `data` of `refund.*` webhooks.

| Property | Type | Notes |
|---|---|---|
| `id` | `string` | |
| `orderId` | `?string` | `null` only for automatic refunds of a blocked wallet; handle it. |
| `source` | `RefundSource` | `Payment`, `Aml`, `BlockedWallet`, `Unknown`. |
| `paymentId`, `walletId` | `?string` | |
| `status` | `RefundStatus` | `Pending`, `Processing`, `Completed`, `Failed`, `Unknown`. |
| `isFinal` | `bool` | Rely on it rather than on the status alone. |
| `failureCode` | `?RefundFailureCode` | `PayoutFailed`, `SystemError`, `AmlRefundFailed`, `GatewayRejected`, `Unknown`. |
| `amount` | `?string` | May be null; handle it. |
| `currencyCode`, `networkCode`, `address` | `string` | |
| `txHash` | `?string` | Null until the transaction exists; handle it. |
| `createdAt`, `updatedAt`, `completedAt` | `?DateTimeImmutable` | UTC. |

### Statuses

`isFinal` comes from the server. The table is derived from the meaning of the statuses; where it says "typically", read `$refund->isFinal`.

| Status | `isFinal` | What the shop should do | Typical next statuses |
|---|---|---|---|
| `Pending` | never | The create returns this. Wait for the `refund.*` webhook. | `Processing`, `Failed` |
| `Processing` | never | Wait. | `Completed`, `Failed` |
| `Completed` | always (typically) | Mark the order refunded; `txHash` is set when the transaction exists. | none |
| `Failed` | always (typically) | Not refunded. Read `failureCode`. The `order_id` is used up: a new attempt needs a new `order_id` and a deliberate decision. | none |
| `Unknown` | read `isFinal` | The SDK does not know this status. Do not act; log it. | |

Keep a default branch: new statuses can appear.

## Read and list

```php
use Enigmoss\Refund\ListRefundsQuery;
use Enigmoss\Refund\RefundSource;
use Enigmoss\Refund\RefundStatus;

$refund = $enigmoss->refunds->get($id);
$refund = $enigmoss->refunds->findByOrderId('refund-42');

foreach ($enigmoss->refunds->iterate(new ListRefundsQuery(status: RefundStatus::Failed, source: RefundSource::Payment)) as $r) {
    echo $r->id, ' ', $r->failureCode?->value, "\n";
}
```

Filters: `orderId`, `source`, `status`, `paymentId`, `walletId`, `createdFrom`, `createdTo`, `limit` (1-100), `cursor`.
