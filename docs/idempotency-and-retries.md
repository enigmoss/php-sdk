# Idempotency and retries

Network failures happen. The SDK makes it safe to repeat the calls that create objects and move money.

## One order_id, one object, forever

Every create endpoint (`payments->create()`, `wallets->create()`, `payouts->create()`, `refunds->createForPayment()`, `refunds->createForWallet()`, `transfers->create()`) takes an `orderId` that **you** choose:

- **One `order_id` is one object forever.** Even a failed payout keeps its `order_id`; a new attempt needs a new `order_id` and a deliberate decision, not an automatic retry. A request that was *rejected* before anything was created does not use the `order_id` up: see [Rejected or failed](#rejected-or-failed).
- The `order_id` is 1-128 letters, digits, `-` or `_`.
- Generate and **persist the `order_id` before the first call**, so a crashed worker can reconcile after a restart.

## The Idempotency-Key

For create endpoints the SDK derives the `Idempotency-Key` from the `order_id` (the value starts with `sdk1-` and is built from a hash of the request path and the `order_id`). That means:

- Repeating the same create returns the **stored response** instead of a `409`, for **24 hours**. A retried queue job or a double click cannot pay twice.
- Payouts, refunds and transfers **require** a key; payments and wallets accept one and the SDK always sends it.
- The same `order_id` on a different endpoint gets a different key (the path is part of the hash).
- Reusing a key with a **different** request body is an error: `IdempotencyException` (`idempotency.payload_mismatch`). Repeating a create must send the same data.
- If the first request is still running, you get `IdempotencyException` (`idempotency.in_progress`). The SDK retries it for about a second, which is far less than a payout needs (up to ~160 s). It is **not** "not executed": `outcomeUnknown()` is `true`, so the call is pending reconcile.
- **Only 2xx responses are stored under the key.** An error is not stored: repeating a request that was rejected runs it again.

Other idempotent actions (`payments->cancel()`, `payments->markPaid()`, `payments->createAmlVerificationLinks()`, `wallets->block()`) get a random UUID per call, reused by the SDK's own retries.

To see what happened, ask for the response metadata:

```php
use Enigmoss\Http\ResponseInfo;
use Enigmoss\RequestOptions;

$payment = $enigmoss->payments->create($request, new RequestOptions(
    onResponse: static function (ResponseInfo $info): void {
        // $info->replayed is true when the server returned the stored response of an earlier request
        error_log(sprintf('request %s key %s replayed=%s attempts=%d', $info->requestId, $info->idempotencyKey, var_export($info->replayed, true), $info->attempts));
    },
));
```

You can override the key per call (1-64 printable ASCII characters, no spaces) with `new RequestOptions(idempotencyKey: '...')`. Do this only if you have your own key scheme.

## Rejected or failed

The `order_id` is used up only when an object exists.

| Case | What happened | The `order_id` | What to do |
|---|---|---|---|
| **Rejected** | A 4xx came back before the object was created: validation, `payout.insufficient_funds`, permission, `429`, a lock. Nothing was created and nothing is stored under the key. | **Free.** | Fix the cause (top up the balance, enable the permission, wait for the rate limit) and repeat with the **same** `order_id`. |
| **Failed** | The object was created and later ended in a failed status (a payout or refund with status `Failed`). | **Used up.** | A new attempt is a new operation: decide deliberately and use a new `order_id`. Repeating the old one returns the failed object (for 24 hours) or a `ConflictException` with `isOrderIdTaken()`. |
| **Unknown** | `outcomeUnknown()` is `true`. | **Possibly used.** | Pending reconcile: look it up with `findByOrderId()`. |

Because only 2xx responses are stored, "no money, top up, repeat the same `order_id`" is the right path after a `payout.insufficient_funds`.

## What the SDK retries

Each endpoint has a retry class:

| Class | Endpoints | Retried when |
|---|---|---|
| Read | All `GET`s, `payouts->quote()` (a calculation), `paymentMethods->updateDiscount()` | Any transient failure: network errors, 429, 502-504, and errors the server marks `retryable`. |
| Money create | Creates, `cancel`, `markPaid`, `block`, AML links | Only when it cannot cause a second effect or hide the original error: the request was **not sent**, an `Idempotency-Key` protects it, the server says it was not executed (`429`, `lock.not_acquired`), the first request with this key is still running (`idempotency.in_progress`; the key makes the retry safe, but the outcome stays unknown), or the API was unreachable (`dependency.unavailable`, a 502/503/504 of the API gateway). |
| Non-idempotent | `events->redeliver()`, `events->redeliverBulk()`, `webhooks->sendTest()` | Only when nothing was sent. |

Important consequences:

- On money-creating endpoints, **business errors are never retried**, even when the server marks them `retryable` (`dependency.gateway`, `dependency.external_service`). A failed payout keeps its `order_id`, so a retry would turn the real cause into a `409`. Only `dependency.unavailable` (the API itself unreachable) is retried, always with the same `Idempotency-Key`.
- **Payout and refund creation is slow.** The gateway is called synchronously and creating a payout may take up to ~160 seconds, so `payouts->create()`, `refunds->createForPayment()` and `refunds->createForWallet()` wait at least 180 seconds per attempt. A larger `ClientConfig::$timeout` is used as is; `RequestOptions::$timeout` overrides both. A shorter timeout does not risk a second payout (the retry carries the same `Idempotency-Key`), but it reports an unknown outcome for a payout that is still being created.
- A `ConnectionException` (the request never left the client) is always retried.
- Delays use exponential backoff with full jitter (0.5 s base, capped at 8 s). A server `Retry-After` is honoured when it is no longer than `maxRetryDelay` (10 s by default); a longer one is thrown to you as `RateLimitException` with `$retryAfter`. `iterate()` waits up to 60 s, because long scans hit the per-IP limit.
- The retry count is `ClientConfig::$maxRetries` (default 2), the total time is bounded, and both can be set per call with `RequestOptions`.
- The body bytes and the `Idempotency-Key` are fixed before the first attempt; the timestamp and signature are fresh on every attempt.

Disable retries entirely with `maxRetries: 0`. The unknown-outcome rules below still apply.

### Retries first, the API origin fallback second

With `ClientConfig::$fallbackBaseUrl` set there is one more step after the retries. When they are over and the last attempt proves that the API host could not be reached at all (DNS, refused connection, TLS handshake, connect timeout), the client asks the fallback host for the current API origin of the project and starts the same request over on it: the same body bytes, the same `Idempotency-Key`, a fresh timestamp and signature, the attempt counter from one, the same total time limit. A request that may have been sent never takes this step, so it cannot create a second object. If the fallback host answers with an error, that error is thrown with the original network error as `getPrevious()`, and its `outcomeUnknown()` is `false`: the original request was not sent. The full rules are in [Configuration](configuration.md#api-origin-fallback).

## When retries give up

After the retries are exhausted the last error is thrown. Then, in this order:

```php
use Enigmoss\Exception\ConflictException;
use Enigmoss\Exception\EnigmossException;

try {
    $payout = $enigmoss->payouts->create($request);
} catch (EnigmossException $e) {
    if ($e->outcomeUnknown()) {
        // Pending reconcile: keep your record "pending", do NOT create again in this request.
        // Look it up later by cron or on the payout.* webhook: findByOrderId().
        // After a few minutes without an object, repeating create() with the SAME order_id is safe.
        return;
    }
    if ($e instanceof ConflictException && $e->isOrderIdTaken()) {
        $payout = $enigmoss->payouts->get((string) $e->existingResourceId());
    } else {
        throw $e;   // definitely not created: fix the cause, the same order_id may be used again
    }
}
```

`IdempotencyException extends ConflictException`: never treat a conflict as "order_id taken" without `isOrderIdTaken()`. See [Errors](errors.md#the-three-outcomes-of-a-money-creating-call) for the three outcomes, the cron reconcile snippet and the full decision table. In Laravel, give payout jobs a long enough timeout: [Laravel](laravel.md#payout-jobs).

## Rate limits

| Limit | Scope |
|---|---|
| 60 requests per minute | per IP |
| 10 payout creations per minute | per project |
| 1 refund creation per minute | per project |
| 10 test webhooks per minute | `webhooks->sendTest()` |
| 1 bulk redelivery per minute | `events->redeliverBulk()` |

A `RateLimitException` carries `$retryAfter` (seconds). `ResponseInfo::$rateLimitLimit` and `$rateLimitRemaining` expose the rate-limit headers of successful responses. When you process many objects, spread the work (a queue with a rate limiter) instead of relying on retries.

## Clock drift

Signatures are valid for a 300 second window. If the server rejects a timestamp, the SDK corrects the offset once from the server time and re-signs. This does not count as a retry. See [Authentication and keys](authentication-and-keys.md#request-signing).
