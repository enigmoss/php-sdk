# Laravel

Supported: Laravel 10, 11, 12 and 13. The service provider and the `Enigmoss` facade are auto-discovered; there is nothing to register.

The provider registers only lazy bindings, so `package:discover` and `config:cache` work without `ENIGMOSS_*` variables set. A missing variable fails with a `ConfigurationException` when the client is first used.

## Install and configure

```bash
composer require enigmoss/php-sdk
```

Add your credentials to `.env`:

```dotenv
ENIGMOSS_BASE_URL=https://api.example.com
ENIGMOSS_PROJECT_ID=3f2b8c1e-5d4a-4e7b-9c6f-1a2b3c4d5e6f
ENIGMOSS_PAYMENT_KEY=...
ENIGMOSS_PAYOUT_KEY=...
```

`ENIGMOSS_BASE_URL` is your API host from the dashboard. Leave `ENIGMOSS_PAYOUT_KEY` out if you do not send money out. Publish the config file only if you need to change its structure (for example to add connections):

```bash
php artisan vendor:publish --tag=enigmoss-config
```

Verify the setup:

```bash
php artisan enigmoss:check
```

### Environment variables

These are the variables read by `config/enigmoss.php`:

| Variable | Default | Meaning |
|---|---|---|
| `ENIGMOSS_CONNECTION` | `default` | Connection used by the facade and by an injected `EnigmossClient`. |
| `ENIGMOSS_BASE_URL` | none, required | API host from the dashboard. |
| `ENIGMOSS_PROJECT_ID` | none, required | Project UUID. |
| `ENIGMOSS_PAYMENT_KEY` | none | Payment key: payments, wallets, balances, webhooks. Also verifies incoming webhooks. |
| `ENIGMOSS_PAYOUT_KEY` | none | Payout key: payouts, refunds, transfers. |
| `ENIGMOSS_TIMEOUT` | `30` | Total time limit of one HTTP attempt, seconds. |
| `ENIGMOSS_CONNECT_TIMEOUT` | `10` | Connect timeout, seconds. |
| `ENIGMOSS_MAX_RETRIES` | `2` | Automatic retries. |
| `ENIGMOSS_MAX_RETRY_DELAY` | `10` | Longest `Retry-After` the SDK waits for, seconds. |
| `ENIGMOSS_ALLOW_INSECURE_HTTP` | `false` | Allow `http://` (local development only). |
| `ENIGMOSS_FALLBACK_BASE_URL` | none | Host asked for the current API origin when `ENIGMOSS_BASE_URL` cannot be reached; see [API origin fallback](#api-origin-fallback). `https://tavrion.org` for the production API; unset turns the fallback off. |
| `ENIGMOSS_LOG_CHANNEL` | none | Log channel for API calls; unset disables logging. |
| `ENIGMOSS_WEBHOOK_TOLERANCE` | `300` | Accepted age of `X-Timestamp`, seconds. |
| `ENIGMOSS_WEBHOOK_QUEUE_CONNECTION` | none | Queue connection of the webhook job; the default when unset. |
| `ENIGMOSS_WEBHOOK_QUEUE` | none | Queue name of the webhook job; the default when unset. |
| `ENIGMOSS_WEBHOOK_DEDUPE_TTL` | `259200` | How long a handled `event_id` is remembered, seconds (3 days). |

Timeouts, retries, insecure-http and the webhook tolerance are global for all connections. Credentials are per connection.

## Using the SDK

### Facade

The facade exposes each service as a **method**:

```php
use Enigmoss\Bridge\Laravel\Facades\Enigmoss;
use Enigmoss\Payment\CreatePaymentRequest;

$payment = Enigmoss::payments()->create(new CreatePaymentRequest(
    amount: '10.50',
    currencyCode: 'USDT',
    orderId: 'order-42',
));

return redirect()->away((string) $payment->paymentUrl);
```

Available: `project()`, `payments()`, `wallets()`, `refunds()`, `payouts()`, `payoutMethods()`, `paymentMethods()`, `exchangeRates()`, `balances()`, `transactions()`, `transfers()`, `events()`, `webhooks()`, `connection(?string $name)`, `client()` and `fake()`.

### Dependency injection

```php
use Enigmoss\EnigmossClient;

final class CheckoutController
{
    public function __construct(private readonly EnigmossClient $enigmoss) {}

    public function __invoke(): \Illuminate\Http\RedirectResponse
    {
        $payment = $this->enigmoss->payments->create(/* ... */);

        return redirect()->away((string) $payment->paymentUrl);
    }
}
```

Injectable: `EnigmossClient` (the default connection), `Enigmoss\Webhook\WebhookVerifier`, `Enigmoss\Webhook\WebhookDeduplicatorInterface` (cache-backed) and `Enigmoss\Bridge\Laravel\EnigmossManager`. The manager caches one client per connection, so sharing it is safe under Octane.

**Do not pass the client to a queued job's constructor.** `EnigmossClient` refuses to be serialized. Resolve it inside `handle()`.

### Multiple connections

One connection per project:

```php
// config/enigmoss.php
'connections' => [
    'default' => [
        'base_url' => env('ENIGMOSS_BASE_URL'),
        'project_id' => env('ENIGMOSS_PROJECT_ID'),
        'payment_key' => env('ENIGMOSS_PAYMENT_KEY'),
        'payout_key' => env('ENIGMOSS_PAYOUT_KEY'),
    ],
    'shop2' => [
        'base_url' => env('ENIGMOSS_BASE_URL'),
        'project_id' => env('ENIGMOSS_SHOP2_PROJECT_ID'),
        'payment_key' => env('ENIGMOSS_SHOP2_PAYMENT_KEY'),
    ],
],
```

```php
$payment = Enigmoss::connection('shop2')->payments->create($request);
```

`Enigmoss::connection()` returns an `EnigmossClient`. Change the default with `ENIGMOSS_CONNECTION`. `enigmoss:check` and `enigmoss:webhook-test` accept `--connection=shop2`.

## Webhooks

### Register the route

```php
use Illuminate\Support\Facades\Route;

Route::enigmossWebhooks();                      // POST /webhooks/enigmoss, route name "enigmoss.webhooks"
Route::enigmossWebhooks('hooks/shop2', 'shop2'); // another URI for the "shop2" connection, name "enigmoss.webhooks.shop2"
```

The macro is `Route::enigmossWebhooks(string $uri = 'webhooks/enigmoss', ?string $connection = null)`. It registers a `POST` route that runs the `enigmoss.webhook[:connection]` middleware (it verifies the signature of the raw body) and the built-in controller.

What happens on a request:

1. The middleware verifies the webhook with the **payment key** of the connection. A failure answers `400` with `{"error": "invalid_webhook", "reason": "<WebhookFailureReason>"}` and nothing is processed.
2. The controller **claims** the `event_id` atomically (a cache `add`, remembered for `ENIGMOSS_WEBHOOK_DEDUPE_TTL` seconds). If it was already claimed, the answer is `200 {"status": "duplicate"}`.
3. Otherwise the controller queues a `HandleEnigmossWebhook` job and answers `200 {"status": "accepted"}`. This is fast, well inside the API's 15 second limit.
4. If queueing fails, the controller **releases the claim** and answers `500`, so the API delivers the webhook again and it is handled.
5. The job (5 tries, backoff 10 s, 1 min, 5 min, 15 min) fires `Enigmoss\Bridge\Laravel\Events\WebhookReceived`. If the job fails for good, its `failed()` method releases the claim too, so a redelivery (the automatic one, or `Enigmoss::events()->redeliver($id)`) is handled again instead of being dropped as a duplicate. You can also run `php artisan queue:retry` for the failed job.

Deduplication needs a cache store with an atomic `add` (redis, memcached, database or array), and the job needs a queue worker (`php artisan queue:work`). With `QUEUE_CONNECTION=sync` the job runs inline and a failing listener makes the endpoint answer `500`.

You can also use only the middleware on your own route: `Route::post('/my/hook', MyController::class)->middleware('enigmoss.webhook:shop2')`; the verified event is then in the request attribute `enigmoss_event` (`Enigmoss\Bridge\Laravel\Http\VerifyEnigmossWebhook::EVENT_ATTRIBUTE`).

### CSRF

`Route::enigmossWebhooks()` is a `POST` route. Registered in `routes/web.php` it runs through the `web` middleware group, and Laravel will answer **419** because the API sends no CSRF token. Do one of:

- register it in `routes/api.php` (the URL then gets the `/api` prefix: `/api/webhooks/enigmoss`), or
- keep it in `routes/web.php` and exclude the URI from CSRF verification. Laravel 11 and later, in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: ['webhooks/enigmoss']);
})
```

On Laravel 10 add `'webhooks/enigmoss'` to `$except` in `App\Http\Middleware\VerifyCsrfToken`.

The route must also be reachable without authentication and must not be behind a middleware that rewrites the body.

### Handle the event

Listen to `WebhookReceived`. `$received->event` is a typed `WebhookEvent`; `$received->connection` is the connection name.

```php
namespace App\Listeners;

use Enigmoss\Bridge\Laravel\Events\WebhookReceived;
use Enigmoss\Event\PaymentEvent;
use Enigmoss\Event\PayoutEvent;

final class HandleEnigmossEvent
{
    public function handle(WebhookReceived $received): void
    {
        $event = $received->event;

        if ($event instanceof PaymentEvent) {
            $payment = $event->payment();

            if ($payment->walletId !== null) {
                // a deposit to a static wallet: no orderId, identify the customer by walletId
            } elseif ($payment->isSuccessful()) {
                // paid and final: mark the order $payment->orderId as paid (idempotently)
            }
        }

        if ($event instanceof PayoutEvent && $event->payout()->isFinal) {
            // settle the withdrawal $event->payout()->orderId
        }
    }
}
```

Register it in **one** way: either rely on Laravel's event auto-discovery (Laravel 11+ discovers listeners in `app/Listeners` by default), **or** call `Event::listen(WebhookReceived::class, HandleEnigmossEvent::class)` in a service provider. Not both: the listener would run twice. The listener runs **inside the already queued job**, so you do not need `ShouldQueue` on it, and if it throws, the job is retried (up to 5 times): keep your handling idempotent. Events can still arrive out of order and delivery is at least once: see [Webhooks](webhooks.md).

## Payout jobs

Creating a payout can take up to ~160 seconds per attempt, and the SDK waits up to 180 seconds per attempt, with retries. The default worker settings are too short: `queue:work` kills a job after `--timeout=60` seconds and `retry_after` is 90 seconds by default, so a second worker would start the same job while the first one is still waiting for the API. Give the payout job its own limits:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Withdrawal;   // your model: order_id, state (new, pending, submitted, failed), payout_id
use Enigmoss\EnigmossClient;
use Enigmoss\Exception\ConflictException;
use Enigmoss\Exception\EnigmossException;
use Enigmoss\Payout\CreatePayoutRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class CreateWithdrawalPayout implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 600;      // at least 600 s: 3 attempts x 180 s do not fit into the default 60 s
    public int $tries = 3;          // a bounded number of restarts for infrastructure errors

    public function __construct(public readonly string $orderId) {}

    public function uniqueId(): string
    {
        return $this->orderId;      // one running job per order_id
    }

    public function handle(EnigmossClient $enigmoss): void
    {
        $withdrawal = Withdrawal::where('order_id', $this->orderId)->firstOrFail();
        if ($withdrawal->state !== 'new') {
            return;                 // already submitted or waiting for reconcile
        }

        $request = new CreatePayoutRequest(
            orderId: $withdrawal->order_id,
            amount: $withdrawal->amount,
            currencyCode: $withdrawal->currency,
            address: $withdrawal->address,
            networkCode: $withdrawal->network,
        );

        try {
            $payout = $enigmoss->payouts->create($request);
        } catch (EnigmossException $e) {
            if ($e->outcomeUnknown()) {
                $withdrawal->update(['state' => 'pending']);   // pending reconcile: a cron or the payout.* webhook resolves it

                return;
            }
            if ($e instanceof ConflictException && $e->isOrderIdTaken()) {
                $payout = $enigmoss->payouts->get((string) $e->existingResourceId());
            } else {
                $withdrawal->update(['state' => 'failed']);    // definitely not created; fix and dispatch again with the same order_id
                throw $e;
            }
        }

        $withdrawal->update(['state' => 'submitted', 'payout_id' => $payout->id]);
    }
}
```

Set the queue connection's `retry_after` **larger than the job timeout**, otherwise the job is released while it still runs:

```php
// config/queue.php, connections.redis (or database, sqs: visibility timeout)
'retry_after' => 660,   // > $timeout of the longest job
```

The `$timeout` property overrides the worker's `--timeout` for this job, but the worker process must still be allowed to live that long: run payout jobs on their own queue (`onQueue('payouts')`) with its own `queue:work --queue=payouts`, and keep `--timeout` smaller than `retry_after`. `ShouldBeUnique` needs a cache driver that supports locks. Never pass the client or the request object to the constructor: pass the `order_id` and read your own record.

## API origin fallback

```dotenv
ENIGMOSS_FALLBACK_BASE_URL=https://tavrion.org
```

Set `ENIGMOSS_FALLBACK_BASE_URL` (the `fallback_base_url` key of a connection; `https://tavrion.org` is the fallback host of the Enigmoss production API) and the client finds the new API host by itself when the configured one cannot be reached: after the usual retries it asks the fallback host for the current API origin of the project, checks the signature of the answer and repeats the call there with the same `Idempotency-Key`. The rules are in [Configuration](configuration.md#api-origin-fallback).

The learned origin is kept in the **default cache store** through `Enigmoss\Bridge\Laravel\CacheOriginStore`, so PHP-FPM requests, Octane workers and queue workers share it. Use a cache that is shared by your processes (`redis`, `memcached`, `database`, `file`); with the `array` store every request asks again. `Enigmoss::fake()` uses no store.

Set `ENIGMOSS_LOG_CHANNEL` to see the move in your logs: it is logged as a warning, "Enigmoss API origin changed", with `origin` and `previous_origin`.

## Artisan commands

| Command | What it does |
|---|---|
| `php artisan enigmoss:check [--connection=]` | Calls `project->get()` once for each configured key and prints the project, the permissions of the key, the API origin in use (and the fallback host, when one is configured) and a clock-drift warning when the drift is above 30 s. Exit code `1` if a key fails. |
| `php artisan enigmoss:webhook-test [--url=] [--connection=]` | Asks the API to send a `webhook.test` event to `--url` (or the project default URL) and prints the delivery status and your endpoint's HTTP status. |

## Logging

Set `ENIGMOSS_LOG_CHANNEL` to a channel from `config/logging.php` (for example `stack`) to log every API call: method, path, status, request id, duration and retries at `debug`, `notice` and `warning` levels. Bodies, headers and keys are never logged. See [Logging and observability](logging-and-observability.md).

## Testing

`Enigmoss::fake()` returns a `MockTransport` and routes every connection through it:

```php
use Enigmoss\Bridge\Laravel\Facades\Enigmoss;
use Enigmoss\Payment\CreatePaymentRequest;
use Enigmoss\Testing\Fixtures;

public function test_it_creates_an_invoice(): void
{
    $transport = Enigmoss::fake();
    $transport->queueData(Fixtures::payment(), 201);

    $payment = Enigmoss::payments()->create(new CreatePaymentRequest('10.50', 'USDT', 'order-42'));

    $this->assertSame('order-42', $payment->orderId);
}
```

`Enigmoss::fake()` works without any `ENIGMOSS_*` configuration: when `base_url`, `project_id` or a key are missing, placeholders are filled in, and no request leaves the process. Real values in `phpunit.xml` are still fine.

You can assert what was sent:

```php
$transport->lastJson()['order_id'];                   // decoded JSON body of the last request
$transport->sent('POST', '/api/v4/payments');        // list<HttpRequest> to that path
```

### Webhook endpoint tests

Add the `InteractsWithEnigmossWebhooks` trait to your test case. `postEnigmossWebhook()` builds a correctly signed webhook with the payment key of the connection and posts the exact signed body (the signature covers the raw bytes, so `postJson()` is not suitable):

```php
use Enigmoss\Bridge\Laravel\Events\WebhookReceived;
use Enigmoss\Bridge\Laravel\Testing\InteractsWithEnigmossWebhooks;
use Enigmoss\Testing\Fixtures;
use Illuminate\Support\Facades\Event;

final class WebhookTest extends TestCase
{
    use InteractsWithEnigmossWebhooks;

    public function test_a_signed_webhook_is_accepted(): void
    {
        Event::fake([WebhookReceived::class]);

        $this->postEnigmossWebhook('payment.paid', Fixtures::paidPayment())
            ->assertOk()
            ->assertJson(['status' => 'accepted']);

        Event::assertDispatched(WebhookReceived::class);
    }
}
```

Signature: `postEnigmossWebhook(string $event, ?array $data, string $uri = '/webhooks/enigmoss', ?string $connection = null, string $eventId = ...)`. Posting the same `$eventId` again returns `{"status": "duplicate"}` and dispatches nothing; pass different event ids to post several events. A body signed with the wrong secret returns `400` with `"reason": "signature_mismatch"` (use `WebhookSimulator::sign()` with another `Secret` and `$this->call(..., $webhook->serverVariables(), $webhook->body)`). Use `cache.default=array` and `queue.default=sync` in tests. The trait reads the payment key and project id of the connection: call `Enigmoss::fake()` first (its placeholders cover an empty configuration) or configure the credentials in the test environment.
