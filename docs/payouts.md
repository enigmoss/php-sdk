# Payouts

Send funds to an external address. Uses the **payout key**: the server IP must be allowed by the project's IP whitelist, otherwise the call fails with `IpNotAllowedException` (`auth.ip_forbidden`). Creating payouts is limited to **10 requests per minute per project** by default.

```php
$enigmoss->payouts;   // Enigmoss\Payout\PayoutService
```

The recommended flow is **quote, create, reconcile**. A create ends in one of three outcomes:

```
quote()  ->  create()  ->  created                 ->  payout.* webhooks / get()
                |
                +-- rejected (4xx, not unknown)   ->  fix the cause, retry with the SAME order_id
                |
                +-- outcomeUnknown() -> pending reconcile -> findByOrderId() later (cron or webhook)
                                        (no object after a few minutes: repeat create() with the SAME order_id)
```

## 1. Quote

`quote()` calculates fee and amounts without creating anything or debiting you. Permission `payout_create`.

```php
use Enigmoss\Payout\QuotePayoutRequest;

$quote = $enigmoss->payouts->quote(new QuotePayoutRequest(
    amount: '100.00',
    currencyCode: 'USDT',
    address: 'TRecipientAddress...',
    networkCode: 'tron',
));

echo $quote->recipient->amount ?? 'n/a';   // what the recipient gets; may be null, handle it
echo $quote->charged->amount ?? 'n/a';     // what is debited from you; may be null, handle it
echo $quote->charged->fee ?? 'n/a';
```

**A quote is informational, not binding.** `create()` takes neither a quote id nor a maximum fee, so the fee may change between the quote and the create. If the fee matters, compare `$payout->charged` with your limit after `create()`.

`QuotePayoutRequest` takes the same fields as `CreatePayoutRequest` minus `orderId`, `memo` and `callbackUrl`. Use **named arguments** (the parameter names are part of the API); `rateSource:` and `priority:` are the last two:

```php
new QuotePayoutRequest(
    amount: '100.00',
    currencyCode: 'USDT',
    address: 'TRecipientAddress...',
    networkCode: 'tron',
    feeDeductedFromAmount: true,
    rateSource: RateSource::Binance,
    priority: PayoutPriority::Economy,
);
```

 Use `payoutMethods->get()` first to read limits, decimals, memo support and per-priority fees; see [Catalog](catalog.md).

`PayoutQuote` properties (the amount fields are `?string`; the SDK does not say when they are null, so handle it): `amount`, `currencyCode`, `networkCode`, `priority`, `recipient` (`PayoutAmount`: `amount`, `currencyCode`), `charged` (`PayoutCharge`: `amount`, `currencyCode`, `fee`), `conversion` (`?PayoutConversion`: `sourceCurrencyCode`, `sourceAmount`, `fee`, `rate`).

## 2. Create

The amount with fee is debited immediately. Permission `payout_create`.

```php
use Enigmoss\Payout\CreatePayoutRequest;
use Enigmoss\Payout\PayoutPriority;

$payout = $enigmoss->payouts->create(new CreatePayoutRequest(
    orderId: 'withdrawal-42',
    amount: '100.00',
    currencyCode: 'USDT',
    address: 'TRecipientAddress...',
    networkCode: 'tron',
    priority: PayoutPriority::Recommended,
    callbackUrl: 'https://shop.example/webhooks/enigmoss',
));
```

| Parameter | Notes |
|---|---|
| `orderId` | Your unique payout ID, 1-128 letters, digits, `-`, `_`. **One `order_id` is one payout, forever, even a failed one.** |
| `amount` | Decimal string, e.g. `"100.00"`. Never a float. |
| `currencyCode` | Currency to send. |
| `address` | Recipient address, 10-128 characters. |
| `networkCode` | Required when the currency has several networks. |
| `memo` | Memo/tag for networks that need it, up to 64 characters. |
| `recipientCurrencyCode` | Currency the recipient gets, when it differs (conversion). |
| `sourceCurrencyCode` | Balance to fund the payout from, when it differs. |
| `feeDeductedFromAmount` | `true` (default): the fee comes out of the amount. |
| `rateSource` | `RateSource` of a payout with conversion: `Binance`, `BinanceP2p`, `Exmo`, `Garantexio`, `Kucoin`. The project setting is used when omitted. |
| `priority` | `PayoutPriority`: `Recommended`, `Economy`, `High`, `Highest`. Higher is faster and costs more. |
| `callbackUrl` | Public URL for `payout.*` webhooks, 6-150 characters. |

### Slow creation

Creating a payout calls the network gateway synchronously and may take up to ~160 seconds. `create()` therefore waits at least 180 seconds per attempt, whatever the default `timeout` is. Call it from a queued job or a CLI command rather than inside a web request, and pass `new RequestOptions(timeout: ...)` only if you have to cut it shorter. With retries, one `create()` can occupy a worker for several minutes: size the job timeout accordingly (Laravel: [Payout jobs](laravel.md#payout-jobs)).

### Money-safe repeats

The `Idempotency-Key` is **required** for payouts and is derived from the `order_id`. A repeated call (a retried job, a double click) returns the same payout and never pays twice. The SDK also never retries business errors on this endpoint: a failed payout keeps its `order_id`, so a retry would only turn the real cause into a `409`.

## 3. Reconcile

If `create()` throws an exception whose `outcomeUnknown()` is `true` (a timeout, a lost connection after sending, a 5xx, a request still in progress), the payout **may exist or may still be created**. Never create a new `order_id` for the same withdrawal, and do not repeat the create inside the same request.

Store your `order_id` and a `pending` state **before** calling `create()`. Check `outcomeUnknown()` first, then `isOrderIdTaken()`:

```php
use Enigmoss\Exception\ConflictException;
use Enigmoss\Exception\EnigmossException;

try {
    $payout = $enigmoss->payouts->create($request);
} catch (EnigmossException $e) {
    if ($e->outcomeUnknown()) {
        // Pending reconcile (timeout, 5xx, idempotency.in_progress): the record stays "pending".
        return;
    }

    if ($e instanceof ConflictException && $e->isOrderIdTaken()) {
        // The order_id belongs to an earlier payout, even a failed one.
        $payout = $enigmoss->payouts->get((string) $e->existingResourceId());
    } else {
        throw $e;   // definitely not created: the same order_id may be used after the fix
    }
}
```

`IdempotencyException extends ConflictException`: without `isOrderIdTaken()` an `idempotency.in_progress` would look like "order_id taken" and lead to a second payout under a new `order_id`.

Reconcile pending withdrawals later, by cron or on the `payout.*` webhook:

```php
$payout = $enigmoss->payouts->findByOrderId($orderId);   // needs info_payouts_list

if ($payout !== null) {
    // save $payout->id and $payout->status; the withdrawal is no longer pending
} elseif ($pendingFor > 300 /* seconds, "a few minutes" */) {
    // Still no object: repeating create() with the SAME order_id is safe
    // (same derived Idempotency-Key). Run it through the try/catch above.
}
```

A full cron snippet is in [Errors](errors.md#reconcile-by-cron). Payout creation can take up to ~160 s, so right after a timeout `findByOrderId()` often returns `null`: that is why you wait instead of creating again.

## The Payout object

The same object is the `data` of `payout.*` webhooks.

| Property | Type | Notes |
|---|---|---|
| `id`, `orderId` | `string` | |
| `status` | `PayoutStatus` | See the table below. |
| `isFinal` | `bool` | Rely on it rather than on the status alone. |
| `failureCode` | `?string` | A gateway code or values like `fail`, `system_fail`, `aml_refund`. Not an enum: treat it as free text. |
| `amount`, `currencyCode`, `networkCode` | `?string` | May be null; handle it. |
| `address` | `string` | |
| `memo` | `?string` | Null when no memo was sent. |
| `priority` | `?PayoutPriority` | |
| `recipient`, `charged`, `conversion` | | As in the quote. Their amount fields are `?string`: may be null, handle it. After `create()` check `charged` against what you expected. |
| `txHash` | `?string` | Null until the transaction exists; may be null, handle it. |
| `createdAt`, `updatedAt` | `?DateTimeImmutable` | UTC. |

### Statuses

`isFinal` comes from the server. The table is derived from the meaning of the statuses; where it says "typically", read `$payout->isFinal` instead of assuming.

| Status | `isFinal` | What the shop should do | Typical next statuses |
|---|---|---|---|
| `Pending` | never | Show "in progress". Do nothing else. | `Processing`, `AwaitingApproval`, `Failed` |
| `Processing` | never | Wait for the webhook. | `Completed`, `Failed` |
| `AwaitingApproval` | never | A large amount waits for manual approval. Wait; do not create another payout. | `Processing`, `Cancelled`, `Failed` (typically) |
| `Completed` | always (typically) | Settle the withdrawal; `txHash` is set when the transaction exists. | none |
| `Failed` | always (typically) | Treat as not paid out. Read `failureCode`, check `balances->list()` / `transactions->list()` before you re-credit your user. The `order_id` is used up: a new attempt needs a new `order_id` and a deliberate decision. | none |
| `Cancelled` | always (typically) | Treat as not paid out, as for `Failed`. | none |
| `Unknown` | read `isFinal` | The SDK does not know this status. Do not act; log it and read `isFinal`. | |

Always keep a default branch: new statuses can appear.

## Read and list

```php
use Enigmoss\Payout\ListPayoutsQuery;
use Enigmoss\Payout\PayoutStatus;

$payout = $enigmoss->payouts->get($id);             // permission info_payout
$payout = $enigmoss->payouts->findByOrderId('withdrawal-42');

foreach ($enigmoss->payouts->iterate(new ListPayoutsQuery(status: PayoutStatus::Failed)) as $p) {
    echo $p->orderId, ' ', $p->failureCode, "\n";
}
```

Filters: `orderId`, `status`, `createdFrom`, `createdTo`, `limit` (1-100), `cursor`. Permission `info_payouts_list`.

## Errors of create

| HTTP | `errorCode` | Exception | Outcome | What to do |
|---|---|---|---|---|
| 422 | `payout.insufficient_funds` | `BusinessRuleException` | Not created | Top up the balance, then repeat with the **same** `order_id`. |
| 403 | `payout.payouts_forbidden` | `PermissionDeniedException` | Not created | Payouts are disabled for the project. Not retryable until it is enabled. |
| 403 | `api_permission.denied` | `PermissionDeniedException` | Not created | Enable `payout_create` for the key. |
| 403 | `auth.ip_forbidden` | `IpNotAllowedException` | Not created | Add the server IP to the whitelist. Catch it before `PermissionDeniedException`. |
| 422 | other business codes (amount limits, unknown network, ...) | `BusinessRuleException` | Not created | Read `errorCode` and `message`; fix the request. |
| 422 | `validation.failed` | `ValidationException` | Not created | See `violations`. |
| 409 | `resource.conflict` with `details.id` | `ConflictException`, `isOrderIdTaken()` is `true` | Created earlier | `payouts->get($e->existingResourceId())`. Do not create a new `order_id`. |
| 409 | `idempotency.in_progress` | `IdempotencyException` | **Unknown** | Pending reconcile. |
| 409 | `idempotency.payload_mismatch` | `IdempotencyException` | Not created | The `order_id` was sent before with different data. |
| 409 | `lock.not_acquired` | `ConflictException` | Not created | Repeat later with the same `order_id`. |
| 429 | `rate_limit.exceeded` | `RateLimitException` | Not created | More than 10 creations per minute: wait `$retryAfter`. |
| 502-504, 500 | `dependency.*`, `internal.*` | `DependencyException`, `ServerException` | **Unknown** | Pending reconcile. |
| none | none | `TransportException` | Unknown after sending | Pending reconcile. |

The other rows (authentication, signature, clock) are in [Errors](errors.md#rows-every-create-endpoint-can-return). A payout never asks you to guess: check `outcomeUnknown()` first, then `isOrderIdTaken()`.
