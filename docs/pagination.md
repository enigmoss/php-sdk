# Pagination

List endpoints use **opaque cursors**, newest first. Each resource service offers the same trio:

| Method | Returns | Use it for |
|---|---|---|
| `list($query)` | `CursorPage` | One page, with control over the cursor. |
| `iterate($query)` | `Generator` | Every matching item, pages fetched lazily. |
| `findByOrderId($orderId)` | `?T` | Reconciliation: one object by your `order_id`. |

Available on `payments`, `wallets`, `refunds`, `payouts`, `transfers` (all three), `transactions` and `events` (`list` and `iterate` only). `balances`, `paymentMethods`, `payoutMethods` and `exchangeRates` return plain lists.

## iterate()

```php
use Enigmoss\Payment\ListPaymentsQuery;
use Enigmoss\Payment\PaymentStatus;

foreach ($enigmoss->payments->iterate(new ListPaymentsQuery(status: PaymentStatus::Paid, limit: 50)) as $payment) {
    // one Payment at a time; the next page is requested only when needed
}
```

`iterate()` returns a `\Generator<int, T>`. Break out of the loop whenever you like: no further pages are fetched. Because it can issue many requests, it waits up to 60 seconds on a `Retry-After` (single calls wait at most `maxRetryDelay`). Do not collect millions of items into an array; process them as you go.

## list() and cursors

```php
$query = new ListPaymentsQuery(limit: 20);
$page = $enigmoss->payments->list($query);

foreach ($page as $payment) {          // CursorPage is iterable and countable
    echo $payment->id, "\n";
}

echo count($page);                      // items on this page
$page->items;                           // list<Payment>
$page->first();                         // first item or null
$page->hasMore;                         // bool
$page->nextCursor;                      // ?string: the next (older) page
$page->prevCursor;                      // ?string: the previous (newer) page
$page->perPage;                         // int
$page->info;                            // ?ResponseInfo of the request

if ($page->hasMore) {
    $next = $enigmoss->payments->list($query->withCursor($page->nextCursor));
}
```

Cursors are opaque: do not build or parse them. Every `List...Query` class has `withCursor(?string)`, which returns a copy with the cursor set. You can store a cursor to resume a scan later; start `iterate()` from a stored cursor by passing a query that already has it.

## Query objects

| Query | Filters | Page size (`limit`) |
|---|---|---|
| `ListPaymentsQuery` | `orderId`, `walletId`, `status`, `createdFrom`, `createdTo` | 1-50 |
| `ListWalletsQuery` | `orderId`, `status`, `currencyCode`, `networkCode`, `createdFrom`, `createdTo` | 1-50 |
| `ListRefundsQuery` | `orderId`, `source`, `status`, `paymentId`, `walletId`, `createdFrom`, `createdTo` | 1-100 |
| `ListPayoutsQuery` | `orderId`, `status`, `createdFrom`, `createdTo` | 1-100 |
| `ListTransfersQuery` | `orderId` | 1-50 |
| `ListTransactionsQuery` | `type`, `createdFrom`, `createdTo` | 1-50 |
| `ListEventsQuery` | `event`, `objectType`, `objectId`, `deliveryStatus`, `createdFrom`, `createdTo` | 1-100 |

The default page size is 20. `createdFrom` and `createdTo` are `DateTimeInterface`: they are sent in UTC. Filtering by an enum case that is `Unknown` is rejected with `InvalidArgumentException`.

## findByOrderId()

```php
$payment = $enigmoss->payments->findByOrderId('order-42');   // ?Payment
```

It is `list(new ListPaymentsQuery(orderId: ..., limit: 1))->first()`. Use it after an error whose `outcomeUnknown()` is true. Payments created as static wallet deposits have no `order_id`: list by `walletId` instead.
