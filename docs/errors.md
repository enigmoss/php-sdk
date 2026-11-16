# Errors

Every failure the SDK raises implements the marker interface `Enigmoss\Exception\EnigmossException`. Catch it to handle all SDK failures at once; catch the subclasses to react precisely.

## Hierarchy

```
Throwable
└── Enigmoss\Exception\EnigmossException            (interface, outcomeUnknown(): bool)
    │
    ├── ApiException                                (RuntimeException: the API answered with an error)
    │   ├── ValidationException                     422 validation.failed, bad/missing Idempotency-Key
    │   ├── BusinessRuleException                   422 / 415 business rule (funds, limits, unavailable method)
    │   ├── AuthenticationException                 401 bad signature, expired timestamp, unknown project, inactive key
    │   ├── PermissionDeniedException               403 permission disabled, payouts forbidden
    │   │   └── IpNotAllowedException              403 auth.ip_forbidden only (IP not allowed, payout key)
    │   ├── NotFoundException                       404 (also objects of another project)
    │   ├── ConflictException                       409 order_id used, state conflict, lock busy
    │   │   └── IdempotencyException               409 idempotency.* (payload_mismatch, in_progress)
    │   ├── RateLimitException                      429
    │   ├── DependencyException                     502 / 503 / 504 upstream gateway failed
    │   └── ServerException                         500
    │
    ├── TransportException                          (RuntimeException: no HTTP response received)
    │   └── ConnectionException                     the connection was never established, nothing was sent
    │
    ├── UnexpectedResponseException                 (RuntimeException: the answer cannot be read)
    ├── WebhookVerificationException                (RuntimeException: an incoming webhook failed verification)
    ├── InvalidArgumentException                    (\InvalidArgumentException: bad value in a request object; nothing sent)
    └── ConfigurationException                      (\LogicException: wrong SDK setup; nothing sent)
```

Notes on the tree:

- `IpNotAllowedException` extends `PermissionDeniedException` and `IdempotencyException` extends `ConflictException`. **Catch the subclass first**, or the parent will swallow it.
- **`IdempotencyException extends ConflictException` - never treat a conflict as "order_id taken" without `isOrderIdTaken()`.** An `idempotency.in_progress` conflict means the first request is still running and the outcome is unknown.
- `IpNotAllowedException` is thrown only for `auth.ip_forbidden`.
- `UnexpectedResponseException`, `WebhookVerificationException`, `InvalidArgumentException` and `ConfigurationException` are not `ApiException`s. They do implement `EnigmossException`, so a single `catch (EnigmossException $e)` still covers them.
- `ApiException` itself is thrown for statuses that map to no other class.

## When each exception is thrown

| Exception | Cause | What to do |
|---|---|---|
| `InvalidArgumentException` | A request object was built with an invalid value (malformed amount, UUID, order_id, out-of-range number, `Unknown` enum case). Thrown by the constructor, before any request. | Fix the code or validate user input. |
| `ConfigurationException` | Bad base URL, no key at all, endpoint needs a key you did not configure, ext-curl missing, no payment key for webhook verification. | Fix the setup. |
| `ValidationException` | The server rejected the request fields. `$violations` lists them. | Show the field messages or fix the request. |
| `BusinessRuleException` | A business rule rejected a well-formed request: insufficient funds, amount limits, unavailable currency or network. | Branch on `$errorCode`. |
| `AuthenticationException` | Bad signature, expired timestamp, unknown project, inactive key. | Check project ID, key type and clock. `serverTime()` is set for `auth.timestamp_expired`. |
| `PermissionDeniedException` | The key may not use the endpoint: permission disabled, payouts forbidden. | Enable the permission in the dashboard. |
| `IpNotAllowedException` | A payout-key request (`auth.ip_forbidden`) from an IP address the project does not allow. | Add the server IP in the dashboard. |
| `NotFoundException` | The object does not exist or belongs to another project (both look the same). | |
| `ConflictException` | `order_id` already used, the object's state forbids the action, or a lock is held. Only `isOrderIdTaken()` tells the first case: then `existingResourceId()` is the object. | If `isOrderIdTaken()`: fetch the object, do not create a new `order_id`. Otherwise read `errorCode` and `details`. |
| `IdempotencyException` | The `Idempotency-Key` was reused for a different request (`payload_mismatch`), or the first request is still running (`in_progress`: **outcome unknown**). | Never reuse a key for different data. For `in_progress` treat the call as pending reconcile (see below). |
| `RateLimitException` | HTTP 429. | Wait `$retryAfter` seconds. |
| `DependencyException` | An upstream gateway failed (502/503/504). | Retry later; reconcile for money calls. |
| `ServerException` | HTTP 500. The outcome is unknown. | Reconcile before creating anything new. |
| `TransportException` | No HTTP response: timeout, connection lost after sending, TLS problem. `$sendPhase` and `$timedOut` tell what happened. | See the decision table. |
| `ConnectionException` | The connection could not be established; the request was certainly not sent. | Safe to retry. |
| `UnexpectedResponseException` | The answer is not JSON, has no `data` envelope, or a field has an unexpected type. On a 2xx the operation most likely **succeeded**. | Reconcile. |
| `WebhookVerificationException` | An incoming webhook failed verification. `$reason` is a `WebhookFailureReason`. | Answer HTTP 400. |

## ApiException members

```php
use Enigmoss\Exception\ApiException;

try {
    $enigmoss->payouts->create($request);
} catch (ApiException $e) {
    $e->httpStatus;       // int
    $e->errorType;        // ErrorType enum
    $e->errorCode;        // string, e.g. 'payout.insufficient_funds' (compare with ErrorCode constants)
    $e->retryable;        // bool: what the server says about retrying the same request
    $e->details;          // array<string, mixed>
    $e->violations;       // list<Violation>
    $e->requestId;        // ?string: quote it to support
    $e->retryAfter;       // ?int seconds
    $e->idempotencyKey;   // ?string: the key the request was sent with
    $e->detail('id');     // a details value as a string, or null
    $e->serverMessage;    // string: error.message exactly as the server sent it
    $e->getMessage();     // log-ready text, see below; do not parse it
}
```

`getMessage()` reads `"<server message> [<code>, request <id>] <hint>"`, for example `Permission denied. [api_permission.denied, request 9f2c...] Enable the "payout_create" API permission in the dashboard.` The request id and the hint are omitted when they are not known. Use `$serverMessage` when you need the server text alone. Webhook verification messages name the missing header or say "verify the raw body with the payment key".

**Branch on the exception class or on `$errorCode`, never on the message text.** Messages are English and may change.

## ErrorType

`Enigmoss\Exception\ErrorType` is the `error.type` of the response: `ValidationError`, `BusinessError`, `AuthenticationError`, `AuthorizationError`, `NotFound`, `Conflict`, `RateLimitError`, `DependencyError`, `InternalError`, `Unknown`. New values may appear in minor releases; keep a default branch.

## ErrorCode constants

Compare `ApiException::$errorCode` with the constants of `Enigmoss\Exception\ErrorCode`. Codes are stable strings, but the server may add new ones at any time: always keep a default branch.

| Group | Constants (value) |
|---|---|
| Shared | `REQUEST_INVALID` (`request.invalid`), `RESOURCE_NOT_FOUND` (`resource.not_found`), `RESOURCE_CONFLICT` (`resource.conflict`), `LOCK_NOT_ACQUIRED` (`lock.not_acquired`), `DOMAIN_RULE_VIOLATION` (`domain.rule_violation`), `VALIDATION_FAILED` (`validation.failed`), `RATE_LIMIT_EXCEEDED` (`rate_limit.exceeded`), `INTERNAL_SERVER_ERROR` (`internal.server_error`), `INTERNAL_UNEXPECTED` (`internal.unexpected`, check the object with a `GET` before sending it again), `DEPENDENCY_GATEWAY` (`dependency.gateway`), `DEPENDENCY_EXTERNAL_SERVICE` (`dependency.external_service`), `DEPENDENCY_UNAVAILABLE` (`dependency.unavailable`, the API or its gateway unreachable) |
| Authentication and access | `SIGNATURE_INVALID` (`auth.signature_invalid`), `TIMESTAMP_EXPIRED` (`auth.timestamp_expired`), `PROJECT_UNKNOWN` (`auth.project_unknown`), `KEY_INACTIVE` (`auth.key_inactive`), `IP_FORBIDDEN` (`auth.ip_forbidden`), `DOMAIN_FORBIDDEN` (`auth.domain_forbidden`, the request came to an API domain not allowed for the project — use the API host from your dashboard), `PERMISSION_DENIED` (`api_permission.denied`) |
| Idempotency | `IDEMPOTENCY_KEY_REQUIRED` (`idempotency.key_required`), `IDEMPOTENCY_KEY_INVALID` (`idempotency.key_invalid`), `IDEMPOTENCY_PAYLOAD_MISMATCH` (`idempotency.payload_mismatch`), `IDEMPOTENCY_IN_PROGRESS` (`idempotency.in_progress`) |
| Payouts | `PAYOUT_PAYOUTS_FORBIDDEN`, `PAYOUT_INSUFFICIENT_FUNDS`, `PAYOUT_NOT_CANCELLABLE` |
| Refunds | `REFUND_PAYOUTS_FORBIDDEN`, `REFUND_PAYMENT_NOT_REFUNDABLE`, `REFUND_WALLET_NOT_REFUNDABLE`, `REFUND_AMOUNT_EXCEEDS_PAID`, `REFUND_AMOUNT_TOO_SMALL`, `REFUND_INSUFFICIENT_FUNDS`, `REFUND_AML_NOT_SUPPORTED`, `REFUND_NOTHING_TO_REFUND` |
| Transfers | `TRANSFER_WALLET_NOT_FOUND`, `TRANSFER_PAYOUTS_FORBIDDEN`, `TRANSFER_PERSONAL_WALLET_NOT_FOUND`, `TRANSFER_INSUFFICIENT_FUNDS`, `TRANSFER_AMOUNT_BELOW_MIN`, `TRANSFER_AMOUNT_ABOVE_MAX` |
| Webhook events API | `WEBHOOK_EVENT_NOT_LATEST`, `WEBHOOK_REDELIVERY_TOO_MANY_EVENTS`, `WEBHOOK_REDELIVERY_LIMIT_REACHED`, `WEBHOOK_EVENT_NOT_REDELIVERABLE` |

The value of the grouped constants follows the pattern `<group>.<name>` in lower case (for example `PAYOUT_INSUFFICIENT_FUNDS` is `payout.insufficient_funds`). Responses that did not come from the API at all (a proxy or load balancer page) are mapped to a code of the form `http.<status>`.

```php
use Enigmoss\Exception\BusinessRuleException;
use Enigmoss\Exception\ErrorCode;
use Enigmoss\Exception\PermissionDeniedException;

try {
    $enigmoss->payouts->create($request);
} catch (PermissionDeniedException $e) {
    // HTTP 403: payouts are disabled for the project, or the permission is missing.
    $message = $e->errorCode === ErrorCode::PAYOUT_PAYOUTS_FORBIDDEN
        ? 'Payouts are disabled for this project.'
        : 'The key may not create payouts: ' . $e->getMessage();
} catch (BusinessRuleException $e) {
    $message = match ($e->errorCode) {
        ErrorCode::PAYOUT_INSUFFICIENT_FUNDS => 'Not enough funds on the balance.',
        default => 'The payout was rejected: ' . $e->getMessage(),
    };
}
```

`IpNotAllowedException` is a `PermissionDeniedException`: put its `catch` first when you want to treat `auth.ip_forbidden` separately.

## Rows every create endpoint can return

The resource pages ([payments](payments.md#errors-of-create), [wallets](wallets.md#errors-of-create), [payouts](payouts.md#errors-of-create), [refunds](refunds.md#errors-of-create), [balances and transfers](balances-and-transfers.md#errors-of-create)) list the codes of their endpoint. These rows apply to all of them:

| HTTP | `errorCode` | Exception | Outcome | What to do |
|---|---|---|---|---|
| 401 | `auth.signature_invalid` | `AuthenticationException` | Not created | Wrong key type (payouts, refunds and transfers need the payout key), wrong project, or the body changed after signing. |
| 401 | `auth.timestamp_expired` | `AuthenticationException` | Not created | Fix the server clock. The SDK already corrected the drift once. |
| 401 | `auth.project_unknown`, `auth.key_inactive` | `AuthenticationException` | Not created | Check `projectId`; enable the API for the project in the dashboard. |
| 403 | `auth.ip_forbidden` | `IpNotAllowedException` | Not created | Add the server IP in the dashboard (payout key). |
| 403 | `auth.domain_forbidden` | `PermissionDeniedException` or `AuthenticationException` (by `error.type`) | Not created | Use the API host from the dashboard as `baseUrl`. |
| 403 | `api_permission.denied` | `PermissionDeniedException` | Not created | Enable the permission named in the message. |
| 422 | `validation.failed` | `ValidationException` | Not created | Read `violations`, fix, repeat with the same `order_id`. |
| 400 / 422 | `idempotency.key_required`, `idempotency.key_invalid` | `ValidationException` or `BusinessRuleException` | Not created | Only with a custom `RequestOptions::$idempotencyKey`: fix the key. |
| 409 | `idempotency.payload_mismatch` | `IdempotencyException` | Not created | The same `order_id` was sent earlier with different data. Repeat with the original data. |
| 409 | `idempotency.in_progress` | `IdempotencyException` | **Unknown** | Pending reconcile. |
| 409 | `lock.not_acquired` | `ConflictException` | Not created | A parallel request holds a lock. The SDK retries it; if it is thrown, repeat later with the same `order_id`. |
| 429 | `rate_limit.exceeded` | `RateLimitException` | Not created | Wait `$retryAfter` seconds, then repeat. |
| 502 / 503 / 504 | `dependency.unavailable`, `dependency.gateway`, `dependency.external_service` | `DependencyException` | **Unknown** | Pending reconcile. |
| 500 | `internal.server_error`, `internal.unexpected` | `ServerException` | **Unknown** | Pending reconcile; check the object with a `GET` before sending it again. |
| none | none | `TransportException` | Unknown after sending | Pending reconcile (see `$sendPhase`). |

## Violations

A `ValidationException` carries `Violation` objects:

| Property | Meaning |
|---|---|
| `field` | Dot path of the field (`"allowed_methods.0.currency_code"`), or `null` for the whole request. |
| `rule` | Machine name of the rule (`required`, `max`, `custom`, ...). |
| `message` | Human-readable English text; do not parse it. |
| `params` | Rule parameters, e.g. `['max' => '128']`. |

```php
use Enigmoss\Exception\ValidationException;

try {
    $enigmoss->payments->create($request);
} catch (ValidationException $e) {
    foreach ($e->violationsFor('amount') as $violation) {
        echo $violation->rule, ': ', $violation->message, "\n";
    }
    foreach ($e->violations as $violation) {
        echo ($violation->field ?? '(request)'), ' ', $violation->rule, "\n";
    }
}
```

## outcomeUnknown()

`EnigmossException::outcomeUnknown()` is `true` when the request **may have been executed** by the server even though the call failed. For an operation that moves money, this is the question that matters.

> When `outcomeUnknown()` is true, never create a new `order_id`. Do not repeat the create in the same request either: store your record as **pending reconcile** and look the object up later with `findByOrderId()`.

| Exception | `outcomeUnknown()` | Why |
|---|---|---|
| `ConnectionException` | `false` | The request never left the client. |
| `TransportException`, `SendPhase::NotSent` | `false` | Same. |
| `TransportException`, `SendPhase::Sent` or `Unknown` | `true` | The server may have processed it (timeout after sending, connection reset). `$timedOut` is set for timeouts. |
| `ApiException` with HTTP 5xx (`ServerException`, `DependencyException`) | `true` | The server failed mid-flight. |
| `IdempotencyException` with `idempotency.in_progress` | `true` | The first request with this key is still running. This is **not** "not executed". |
| Other `ApiException` (4xx) | `false` | The server rejected the request: it was not executed. |
| `UnexpectedResponseException` with a 2xx status | `true` | The operation most likely succeeded, but the answer is unreadable. |
| `UnexpectedResponseException` otherwise | `false` | |
| `WebhookVerificationException`, `InvalidArgumentException`, `ConfigurationException` | `false` | Nothing was sent. |

### The three outcomes of a money-creating call

Every create of a payout, refund or transfer (and, with the same logic, of an invoice or a wallet) ends in one of three states. Model all three in your own database:

| Outcome | How you know | What to do |
|---|---|---|
| **Created** | The call returned an object, or `isOrderIdTaken()` points to it. | Save `id` and `status`; follow `payout.*` / `refund.*` webhooks. |
| **Definitely not created** | `outcomeUnknown()` is `false` and the error is not "order_id taken" (validation, insufficient funds, permission, 4xx). | Fix the cause. The **same** `order_id` may be used again: a rejected request does not consume it (see [Idempotency and retries](idempotency-and-retries.md#rejected-or-failed)). |
| **Pending reconcile** (unknown) | `outcomeUnknown()` is `true`. | Keep your record as `pending`. Do **not** repeat the create in the same request and never use a new `order_id`. Reconcile later by cron or by the webhook with `findByOrderId()`. If after a few minutes there is still no object, repeating the create with the **same** `order_id` is safe. |

Why not repeat at once: creating a payout or a refund can take up to ~160 seconds, so right after a timeout or `idempotency.in_progress` the object often does not exist yet, and a second create can run into the same unknown outcome.

### The catch order

Check `outcomeUnknown()` **first**, then `isOrderIdTaken()`:

```php
use Enigmoss\Exception\ConflictException;
use Enigmoss\Exception\EnigmossException;

try {
    $payout = $enigmoss->payouts->create($request);
} catch (EnigmossException $e) {
    if ($e->outcomeUnknown()) {
        // Pending reconcile: mark your record "pending" and stop. Also covers idempotency.in_progress.
        $store->markPending($request->orderId);

        return;
    }

    if ($e instanceof ConflictException && $e->isOrderIdTaken()) {
        // The order_id already belongs to a payout (a failed payout keeps its order_id too).
        $payout = $enigmoss->payouts->get((string) $e->existingResourceId());   // non-null when isOrderIdTaken()
    } else {
        // Definitely not created: validation, insufficient funds, permission, state conflict...
        throw $e;
    }
}
```

`ConflictException::isOrderIdTaken()` is `true` only when a create failed because the `order_id` already belongs to an object (`existingResourceId()` is that object). It is `false` for `IdempotencyException` (request in progress), for a busy lock (`lock.not_acquired`) and for an object whose state does not allow the action.

### Reconcile by cron

```php
<?php

declare(strict_types=1);

use Enigmoss\Exception\EnigmossException;

/**
 * @param iterable<array{order_id: string, since: DateTimeImmutable}> $pending your own "pending reconcile" rows
 */
function reconcilePayouts(Enigmoss\EnigmossClient $enigmoss, iterable $pending, callable $onFound, callable $onRepeat): void
{
    foreach ($pending as $row) {
        $payout = $enigmoss->payouts->findByOrderId($row['order_id']);   // ?Payout
        if ($payout !== null) {
            $onFound($row['order_id'], $payout);   // save id and status, leave "pending"
            continue;
        }

        if ($row['since'] < new DateTimeImmutable('-5 minutes')) {
            // Still no object after a few minutes: repeating the create with the SAME order_id is safe.
            // Run it through the same try/catch as above (outcomeUnknown first); it may stay pending again.
            $onRepeat($row['order_id']);
        }
    }
}
```

The `payout.*` and `refund.*` webhooks do the same job without polling: when one arrives for your `order_id`, the pending state is resolved. Both ways work with the same `findByOrderId()` and need the permission that lists the objects.

## TransportException details

```php
use Enigmoss\Exception\SendPhase;
use Enigmoss\Exception\TransportException;

try {
    $enigmoss->project->get();
} catch (TransportException $e) {
    $e->sendPhase;          // SendPhase::NotSent | Sent | Unknown
    $e->timedOut;           // bool
    $e->hostUnreachable;    // bool: the connection to the API host was never established
    $e->idempotencyKey();   // the key the request was sent with, repeat with it
    $e->getPrevious();      // the underlying curl / PSR-18 / Symfony exception
}
```

`SendPhase::Unknown` is treated exactly like `Sent`.

`hostUnreachable` is `true` only when the transport knows that the API host itself could not be reached (DNS, refused connection, TLS handshake, connect timeout) and no proxy stands in between; the built-in `CurlTransport` sets it. It is what starts the [API origin fallback](configuration.md#api-origin-fallback) when `ClientConfig::$fallbackBaseUrl` is set.

### Errors of the fallback host

When the fallback host answers but gives no usable API origin, the call throws the exception of that answer (`NotFoundException`, `RateLimitException`, `ServerException`, `UnexpectedResponseException` for a missing or wrong signature, ...). You can tell it apart by three things: the message starts with `API origin fallback failed:`, `getPrevious()` is the `ConnectionException` of your original request, and `outcomeUnknown()` is `false` even for a 5xx, because your request was not sent. Treat it like the network error it wraps: nothing was created, repeat later with the same `order_id`.
