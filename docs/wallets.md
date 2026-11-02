# Wallets

A static wallet is a permanent deposit address bound to your `order_id` (a customer, an account). Every deposit to it becomes a [`Payment`](payments.md) with `walletId` set. Uses the **payment key**.

```php
$enigmoss->wallets;   // Enigmoss\Wallet\WalletService
```

## Create a wallet

Permission `payment_create_static_wallet`.

```php
use Enigmoss\Wallet\CreateWalletRequest;

$wallet = $enigmoss->wallets->create(new CreateWalletRequest(
    currencyCode: 'USDT',
    networkCode: 'tron',
    orderId: 'customer-7',
    callbackUrl: 'https://shop.example/webhooks/enigmoss',
));

echo $wallet->address;      // the deposit address
echo $wallet->paymentUrl;   // hosted page with the address and QR code
```

| Parameter | Notes |
|---|---|
| `currencyCode`, `networkCode` | Required, e.g. `USDT` and `tron`. |
| `orderId` | Your ID of the wallet owner. **One `order_id` is one wallet, forever.** |
| `callbackUrl` | Public URL for the `payment.*` webhooks of deposits to this wallet. |
| `referralCode` | Optional. |

`create()` is safe to repeat with the same `order_id`: the default `Idempotency-Key` is derived from it (stored response for 24 hours, then a `ConflictException`).

### Errors of create

| HTTP | `errorCode` | Exception | Outcome | What to do |
|---|---|---|---|---|
| 422 | `validation.failed` | `ValidationException` | Not created | See `violations`. |
| 422 | unavailable currency or network | `BusinessRuleException` | Not created | Read `errorCode`; pick another currency or network. |
| 409 | `resource.conflict` with `details.id` | `ConflictException`, `isOrderIdTaken()` is `true` | Created earlier | `wallets->get($e->existingResourceId())`. |
| 409 | `idempotency.in_progress` | `IdempotencyException` | **Unknown** | Look it up with `findByOrderId()` later. |
| 409 | `idempotency.payload_mismatch` | `IdempotencyException` | Not created | The `order_id` was sent before with different data. |
| 403 | `api_permission.denied` | `PermissionDeniedException` | Not created | Enable `payment_create_static_wallet`. |
| 429 | `rate_limit.exceeded` | `RateLimitException` | Not created | Wait `$retryAfter`. |
| 502-504 | `dependency.*` | `DependencyException` | **Unknown** | The gateway could not create the address. Look it up with `findByOrderId()`, then retry later. |
| 500, none | `internal.*`, no response | `ServerException`, `TransportException` | **Unknown** | `findByOrderId()` before creating again. |

Shared rows: [Errors](errors.md#rows-every-create-endpoint-can-return).

### findOrCreate: one wallet per customer

Because one `order_id` is one wallet forever, "get or create the wallet of customer 7" is a small recipe: look up, create, and on `isOrderIdTaken()` fetch by the id the server returns.

```php
use Enigmoss\Exception\ConflictException;
use Enigmoss\Exception\EnigmossException;
use Enigmoss\Wallet\CreateWalletRequest;
use Enigmoss\Wallet\Wallet;

function findOrCreateWallet(Enigmoss\EnigmossClient $enigmoss, CreateWalletRequest $request): ?Wallet
{
    $wallet = $enigmoss->wallets->findByOrderId($request->orderId);
    if ($wallet !== null) {
        return $wallet;
    }

    try {
        return $enigmoss->wallets->create($request);
    } catch (EnigmossException $e) {
        if ($e->outcomeUnknown()) {
            return null;   // unknown: ask again later with findByOrderId(), do not use a new order_id
        }
        if ($e instanceof ConflictException && $e->isOrderIdTaken()) {
            return $enigmoss->wallets->get((string) $e->existingResourceId());
        }

        throw $e;
    }
}
```

The wallet is per `order_id`, so use a stable value such as `customer-7` and keep it in your database.

## The Wallet object

| Property | Type | Notes |
|---|---|---|
| `id` | `string` | UUID. |
| `orderId` | `?string` | Your ID. May be null; handle it. |
| `currencyCode`, `networkCode`, `address` | `string` | |
| `status` | `WalletStatus` | `Active`, `Blocked`, `Inactive`, `Unknown`. |
| `refundIncoming` | `bool` | Whether deposits arriving after blocking are refunded. |
| `callbackUrl` | `?string` | Null when none was set. |
| `paymentUrl` | `string` | Hosted page that shows the address and QR code. |
| `createdAt`, `updatedAt` | `?DateTimeImmutable` | UTC. |

## Read and list

```php
use Enigmoss\Wallet\ListWalletsQuery;
use Enigmoss\Wallet\WalletStatus;

$wallet = $enigmoss->wallets->get($id);
$wallet = $enigmoss->wallets->findByOrderId('customer-7');   // ?Wallet

foreach ($enigmoss->wallets->iterate(new ListWalletsQuery(status: WalletStatus::Active, networkCode: 'tron')) as $w) {
    echo $w->address, "\n";
}
```

Filters: `orderId`, `status`, `currencyCode`, `networkCode`, `createdFrom`, `createdTo`, `limit` (1-50), `cursor`. See [Pagination](pagination.md).

## Deposits

List the deposits of a wallet through payments:

```php
use Enigmoss\Payment\ListPaymentsQuery;

foreach ($enigmoss->payments->iterate(new ListPaymentsQuery(walletId: $wallet->id)) as $deposit) {
    echo $deposit->paid->amount, ' ', $deposit->currencyCode, ' ', $deposit->status->value, "\n";
}
```

Deposits also arrive as `payment.*` webhooks whose `Payment::$walletId` is set and `orderId` is `null`. Use `walletId` to find the customer.

## Block a wallet

Permission `wallet_block`. Blocked wallets do not credit new deposits.

```php
$wallet = $enigmoss->wallets->block($wallet->id, refundIncoming: true);
```

`refundIncoming` is fixed at the **first** block and cannot be undone. Repeating the call with the same value returns the wallet unchanged. A `ConflictException` means the wallet is deactivated or was blocked with a different `refundIncoming`.

A blocked wallet can be refunded once with `refunds->createForWallet()`: see [Refunds](refunds.md#refund-a-blocked-wallet).
