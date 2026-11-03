# Authentication and keys

## Two API keys

Each project has two API keys. The SDK picks the right one for every call; you only supply them in `ClientConfig`.

| Key | `ClientConfig` option | Used for |
|---|---|---|
| **Payment key** | `paymentKey` | Payments, static wallets, AML verification links, balances, transactions, the payment-method discount `PATCH`, the webhook events journal, test webhooks, **and verifying incoming webhooks**. |
| **Payout key** | `payoutKey` | Payouts, payout quotes, refunds and transfers. Requests signed with it are subject to the **project IP whitelist**. |
| **Either key** | either | `project->get()`, `paymentMethods->list()`, `exchangeRates->list()`, `payoutMethods->list()` and `payoutMethods->get()`. When both keys are configured, the payment key is used. |

Pass only the keys you use. Calling an endpoint whose key is not configured throws `ConfigurationException` before anything is sent.

If you see an `AuthenticationException` (HTTP 401, `auth.signature_invalid`) while the key looks right, you usually used the wrong key type: payouts, refunds and transfers need the payout key, everything else the payment key.

## Request signing

Every request is signed automatically with HMAC-SHA256 over the timestamp, project ID, HTTP method, request URI (path with query, as sent) and the exact body bytes. The SDK sets `X-Project-Uuid`, `X-Timestamp` and `X-Signature`; you never build them yourself. Timestamp and signature are fresh on every attempt, including retries.

**Clock drift.** The server accepts timestamps within a 300 second window. Keep your servers on NTP. If the server answers `auth.timestamp_expired`, the SDK compares its clock with the server time in the error and, if they differ by 30 seconds or more, re-signs and retries once. This correction does not count as a retry and is safe because authentication runs before any business logic. `project->get()` returns `serverTime` if you want to monitor drift yourself.

## The IP whitelist for the payout key

Requests signed with the payout key must come from an IP address your project allows. A request from an IP address the project does not allow gets `IpNotAllowedException` (error code `auth.ip_forbidden`; this is the only code that exception is thrown for). Add the server's outbound IP in the dashboard. Remember that autoscaled or serverless workloads may use a rotating IP: route payout calls through a fixed egress address.

```php
use Enigmoss\Exception\IpNotAllowedException;

try {
    $enigmoss->payouts->create($request);
} catch (IpNotAllowedException $e) {
    // Add this server's IP to the whitelist in the dashboard. The payout was not created.
}
```

## Which keys a scenario needs

Pass only the keys you use, but check the whole scenario: some need both keys, and some look like they belong to one key and need the other.

| Scenario | Payment key | Payout key | Why |
|---|---|---|---|
| Accept payments (create invoices, read them) | yes | no | |
| Verify any incoming webhook | yes | no | Webhooks are signed with the **payment key**, including `payout.*` and `refund.*`. |
| Create payouts, read their status | no | yes | |
| Receive `payout.*` webhooks | **yes** | not needed | A worker that holds only the payout key cannot verify them. |
| Check balances before a payout (`balances->list()`) | **yes** | yes (for the payout) | `balances` need the payment key. |
| Quote a payout | no | yes | |
| Refund a payment | no | yes | Receive `refund.*` webhooks with the payment key. |
| Block a static wallet and refund it | yes (`wallets->block()`) | yes (`refunds->createForWallet()`) | **Both keys.** |
| Transfer to the personal account | no | yes | |
| Replay or redeliver webhooks (events journal) | yes | no | |
| `project->get()`, catalog and rates | either | either | |

## Permissions

Each endpoint also requires a permission enabled for your project. A missing permission gives `PermissionDeniedException` (HTTP 403, `api_permission.denied`). `project->get()` lists the permissions the current key can use.

Rate limits: 60 requests per minute per IP overall; payout creation 10 per minute and refund creation 1 per minute per project; see [Idempotency and retries](idempotency-and-retries.md#rate-limits).

## Endpoint, key and permission matrix

| Service method | HTTP | Key | Permission |
|---|---|---|---|
| `project->get()` | `GET /project` | either | none |
| `payments->create()` | `POST /payments` | payment | `payment_create_invoice` |
| `payments->get()` | `GET /payments/{id}` | payment | `info_payments` |
| `payments->list()`, `iterate()`, `findByOrderId()` | `GET /payments` | payment | `info_payments` |
| `payments->cancel()` | `POST /payments/{id}/cancel` | payment | `payment_cancel` |
| `payments->markPaid()` | `POST /payments/{id}/mark-paid` | payment | `payment_mark_paid` |
| `payments->createAmlVerificationLinks()` | `POST /payments/{id}/aml-verification-links` | payment | `info_payments` |
| `wallets->create()` | `POST /wallets` | payment | `payment_create_static_wallet` |
| `wallets->get()` | `GET /wallets/{id}` | payment | `info_payments` |
| `wallets->list()`, `iterate()`, `findByOrderId()` | `GET /wallets` | payment | `info_payments` |
| `wallets->block()` | `POST /wallets/{id}/block` | payment | `wallet_block` |
| `balances->list()` | `GET /balances` | payment | `info_balance` |
| `transactions->list()`, `iterate()` | `GET /transactions` | payment | `info_transactions_history` |
| `paymentMethods->list()` | `GET /payment-methods` | either | none |
| `paymentMethods->updateDiscount()` | `PATCH /payment-methods/{currency}/{network}` | payment | `payment_settings` |
| `events->list()`, `iterate()`, `get()` | `GET /events`, `GET /events/{id}` | payment | `webhooks` |
| `events->redeliver()`, `redeliverBulk()` | `POST /events/{id}/redeliver`, `POST /events/redeliver` | payment | `webhooks` |
| `webhooks->sendTest()` | `POST /webhooks/test` | payment | `webhooks` |
| `payouts->create()` | `POST /payouts` | payout | `payout_create` |
| `payouts->quote()` | `POST /payouts/quote` | payout | `payout_create` |
| `payouts->get()` | `GET /payouts/{id}` | payout | `info_payout` |
| `payouts->list()`, `iterate()`, `findByOrderId()` | `GET /payouts` | payout | `info_payouts_list` |
| `refunds->createForPayment()` | `POST /payments/{id}/refunds` | payout | `payment_refund` |
| `refunds->createForWallet()` | `POST /wallets/{id}/refunds` | payout | `payment_refund` |
| `refunds->get()`, `list()`, `iterate()`, `findByOrderId()` | `GET /refunds/{id}`, `GET /refunds` | payout | `payment_refund` |
| `transfers->create()`, `get()`, `list()`, `iterate()`, `findByOrderId()` | `/transfers` | payout | `transfer_to_personal` |
| `exchangeRates->list()` | `GET /exchange-rates` | either | none |
| `payoutMethods->list()`, `get()` | `GET /payout-methods[/{currency}/{network}]` | either | none |
| `webhooks->constructEvent()` (verifying incoming webhooks) | local, no request | payment | none |

All paths are relative to `/api/v4`.

## Escape hatch

Endpoints the SDK does not model yet are reachable through `EnigmossClient::request()` with the same signing, idempotency, retries and typed exceptions. Choose the key with `KeyScope`:

```php
use Enigmoss\Auth\KeyScope;

$raw = $enigmoss->request('POST', '/payments/' . rawurlencode($id) . '/cancel', [], KeyScope::Payment, idempotent: true);
$data = $raw->data;   // decoded "data" member
```

`KeyScope` has three cases: `Payment`, `Payout`, `Any`. The path is relative to `/api/v4`, must start with `/` and must not contain a query string (pass it as `$query`) or `{`. An endpoint your project cannot use fails with a typed exception like any other call.
