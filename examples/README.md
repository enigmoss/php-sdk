# Examples

Runnable scripts for the Enigmoss PHP SDK. Install the dependencies first:

```bash
composer install
```

Every script reads its configuration from environment variables:

| Variable | Meaning |
|---|---|
| `ENIGMOSS_BASE_URL` | Your API host from the dashboard, e.g. `https://api.example.com` |
| `ENIGMOSS_PROJECT_ID` | Project UUID |
| `ENIGMOSS_PAYMENT_KEY` | Payment API key (payments, wallets, webhooks) |
| `ENIGMOSS_PAYOUT_KEY` | Payout API key (payouts, refunds, transfers) |

```bash
export ENIGMOSS_BASE_URL=https://api.example.com
export ENIGMOSS_PROJECT_ID=3f2b8c1e-5d4a-4e7b-9c6f-1a2b3c4d5e6f
export ENIGMOSS_PAYMENT_KEY=...
export ENIGMOSS_PAYOUT_KEY=...
```

## Test mode: there is none, so run offline

There is no documented sandbox or test mode in the API. Against the real API the scripts create real objects, and `payout-flow.php` and `refund.php` move real money. Use a dedicated test project with small amounts, or run offline.

### Offline mode

Set `ENIGMOSS_EXAMPLE_MOCK=<scenario>` and any script runs against an in-memory `MockTransport`: no request leaves your machine, no keys are needed and no money moves.

```bash
ENIGMOSS_EXAMPLE_MOCK=happy php examples/create-payment.php
ENIGMOSS_EXAMPLE_MOCK=unknown_pending php examples/payout-flow.php TRecipientabcdefghijklmnopqrstuvwx
ENIGMOSS_EXAMPLE_MOCK=in_progress php examples/refund.php 7c9e6679-7425-40de-944b-e07fc1f90ae7 TCustomerabcdefghijklmnopqrstuvwxy
```

| Scenario | What it simulates | Scripts |
|---|---|---|
| `happy` | Everything succeeds. | all |
| `order_taken` | The create answers 409 and the `order_id` already belongs to an object; the script fetches it. | `create-payment`, `payout-flow`, `refund` |
| `in_progress` | The server answers `idempotency.in_progress`: the outcome is unknown. | `create-payment`, `payout-flow`, `refund` |
| `unknown_found` | The create times out, then `findByOrderId()` finds the payout. | `payout-flow` |
| `unknown_pending` | The create times out and the payout is not visible yet: pending reconcile. | `payout-flow` |

The scenarios are defined in [`_mock.php`](_mock.php). An unknown scenario name prints the available ones.

### Exit codes

| Code | Meaning |
|---|---|
| `0` | Done. |
| `1` | Definitely not created or another error: fix the cause. |
| `2` | Usage error or missing configuration. |
| `3` | **Pending reconcile**: the outcome of a money call is unknown (`create-payment.php`, `payout-flow.php`, `refund.php`). Keep your record pending and look the object up later with the same `order_id`; do not create a new one. |

`tests/Examples/ExamplesTest.php` runs every script offline in CI (`composer test` runs the suites `unit`, `examples` and `bridge`), so the examples always behave as documented.

| Script | Key | What it shows |
|---|---|---|
| [`create-payment.php`](create-payment.php) | payment | Create an invoice and print the `paymentUrl`; validation and conflict handling |
| [`static-wallet.php`](static-wallet.php) | payment | Create a static wallet and list its deposits |
| [`payout-flow.php`](payout-flow.php) | payout | Quote, create, and reconcile an unknown outcome with `findByOrderId()` |
| [`refund.php`](refund.php) | payout | Refund a paid invoice; rate limit and conflict handling |
| [`webhook-endpoint.php`](webhook-endpoint.php) | payment | A plain-PHP webhook endpoint: verify, claim the event id (atomic deduplication), acknowledge fast |
| [`auto-pagination.php`](auto-pagination.php) | payment | `iterate()`, manual cursors and `findByOrderId()` |

Run the webhook endpoint locally with the built-in server and send it a signed test event from the API:

```bash
php -S localhost:8000 examples/webhook-endpoint.php
```

The endpoint deduplicates with `PdoWebhookDeduplicator` on the database in `ENIGMOSS_WEBHOOK_DSN` (a PDO DSN; a local SQLite file when unset) and creates the `enigmoss_webhook_events` table if it is missing. It needs only `ENIGMOSS_PROJECT_ID` and `ENIGMOSS_PAYMENT_KEY`. `localhost` is not reachable from the API: expose the port with a tunnel, or test the endpoint without the API by posting a body signed with `WebhookSimulator` (see [Testing](../docs/testing.md)).

The documentation lives in [`docs/`](../docs/).
