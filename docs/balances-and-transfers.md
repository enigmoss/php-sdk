# Balances, transactions and transfers

## Balances

Business balances, one per currency. **Payment key** (even if you only use the payout key for everything else), permission `info_balance`.

```php
foreach ($enigmoss->balances->list() as $balance) {
    echo $balance->currencyCode, ': ', $balance->balance;
    echo $balance->balanceUsd !== null ? " (~{$balance->balanceUsd} USD)\n" : "\n";
}
```

`Balance` has `currencyCode`, `balance` (a decimal string) and `balanceUsd` (USD equivalent at the current rate, 2 decimals; `null` when no rate is available).

## Transactions

Balance movements of the project, newest first. **Payment key**, permission `info_transactions_history`.

```php
use Enigmoss\Transaction\ListTransactionsQuery;
use Enigmoss\Transaction\TransactionType;

$page = $enigmoss->transactions->list(new ListTransactionsQuery(type: TransactionType::Payout, limit: 50));

foreach ($enigmoss->transactions->iterate(new ListTransactionsQuery(createdFrom: new DateTimeImmutable('-1 day'))) as $tx) {
    echo $tx->type->value, ' ', $tx->amount, ' (fee ', $tx->fee, ') ', $tx->currencyCode, "\n";
}
```

Filters: `type`, `createdFrom`, `createdTo`, `limit` (1-50), `cursor`. `TransactionType::Other` (a legacy movement type) can be read but not used as a filter.

`Transaction` properties: `id`, `type`, `amount`, `fee`, `currencyCode`, `networkCode`, `paymentId`, `payoutId`, `refundId`, `transferId` (the one that matches the type is set), `createdAt`.

`TransactionType` cases: `Payment`, `PaymentHold`, `Payout`, `Refund`, `Transfer`, `ConversionOut`, `ConversionIn`, `Other`, `Unknown`. Keep a default branch: new types may appear.

## Transfers to the personal account

Moves money from the business balance to the personal account, without a fee. **Payout key**, permission `transfer_to_personal` (and the IP whitelist applies: a request from an IP the project does not allow gets `IpNotAllowedException`).

```php
use Enigmoss\Transfer\CreateTransferRequest;

$transfer = $enigmoss->transfers->create(new CreateTransferRequest(
    orderId: 'transfer-1',
    amount: '25.00',
    currencyCode: 'USDT',
));

echo $transfer->id, ' debited by transaction ', $transfer->transactionId;
```

`amount` is a decimal string with at most 11 integer digits and 8 decimals. Like payouts, `create()` is money-safe to repeat with the same `order_id` (the `Idempotency-Key` is required and derived from it), and `outcomeUnknown()` rules apply: look up with `findByOrderId()` before doing anything else.

```php
$transfer = $enigmoss->transfers->findByOrderId('transfer-1');   // ?Transfer
$transfer = $enigmoss->transfers->get($id);

foreach ($enigmoss->transfers->iterate() as $t) {
    echo $t->orderId, ' ', $t->amount, "\n";
}
```

`ListTransfersQuery` filters: `orderId`, `limit` (1-50), `cursor`.

`Transfer` properties: `id`, `orderId`, `amount`, `currencyCode`, `transactionId` (the debiting movement in `transactions`), `createdAt`.

## Errors of create

A transfer is synchronous: a returned transfer means the money has moved.

| HTTP | `errorCode` | Exception | Outcome | What to do |
|---|---|---|---|---|
| 422 | `transfer.insufficient_funds` | `BusinessRuleException` | Not created | Top up, then repeat with the same `order_id`. |
| 422 | `transfer.amount_below_min`, `transfer.amount_above_max` | `BusinessRuleException` | Not created | Fix the amount. |
| 422 | `transfer.personal_wallet_not_found`, `transfer.wallet_not_found` | `BusinessRuleException` | Not created | No personal account or wallet for this currency. |
| 403 | `transfer.payouts_forbidden` | `PermissionDeniedException` | Not created | Transfers are disabled for the project. Enable payouts for the project in the dashboard. |
| 403 | `auth.ip_forbidden` | `IpNotAllowedException` | Not created | Add the server IP to the whitelist. |
| 409 | `resource.conflict` with `details.id` | `ConflictException`, `isOrderIdTaken()` is `true` | Created earlier | `transfers->get($e->existingResourceId())`. |
| 409 | `idempotency.in_progress` | `IdempotencyException` | **Unknown** | Pending reconcile with `findByOrderId()`. |
| 5xx, none | `dependency.*`, `internal.*`, no response | `DependencyException`, `ServerException`, `TransportException` | **Unknown** | Pending reconcile; never use a new `order_id`. |

Shared rows: [Errors](errors.md#rows-every-create-endpoint-can-return). Check `outcomeUnknown()` first, then `isOrderIdTaken()`; `IdempotencyException extends ConflictException`.
