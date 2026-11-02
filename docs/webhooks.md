# Webhooks

The API notifies your server about invoice, payout and refund changes with signed HTTP `POST` requests. Webhooks are signed with your **payment key**, even for `payout.*` and `refund.*` events.

## Delivery rules

| Rule | Detail |
|---|---|
| Method and headers | `POST` with `X-Project-Uuid`, `X-Timestamp` and `X-Signature`. |
| Signature | `hex(hmac_sha256("{timestamp}\n{project}\n{raw body}", payment key))`. |
| Acknowledge | Answer **2xx within 15 seconds**. Do heavy work in a queue. |
| Retries | Up to **9 attempts over about 2 days** until you answer 2xx. |
| Duplicates | The same event can arrive more than once. **Deduplicate by event id.** |
| Ordering | Events can arrive **out of order**. |
| Destination | The `callbackUrl` of the object, or your project default URL. |

## Verify and parse

`WebhookVerifier` checks, in order: the three headers are present, `X-Project-Uuid` is your project, `X-Timestamp` is within the tolerance (default 300 seconds), and the signature matches. Any failure throws `WebhookVerificationException`; answer it with **HTTP 400** and do not process the request. The message names what is wrong: the missing header, or "verify the raw body with the payment key" for a signature mismatch.

```php
use Enigmoss\Exception\WebhookVerificationException;
use Enigmoss\Secret;
use Enigmoss\Webhook\WebhookVerifier;

$verifier = new WebhookVerifier($projectId, new Secret($paymentKey));

try {
    $event = $verifier->constructEvent(
        (string) file_get_contents('php://input'),   // the RAW body
        WebhookVerifier::headersFromGlobals(),
    );
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    error_log('Rejected webhook: ' . $e->reason->value);
    exit;
}
```

If you already have a client with the payment key, `$enigmoss->webhooks->constructEvent($rawBody, $headers)` does the same (it throws `ConfigurationException` when no payment key is configured). `$enigmoss->webhooks->verifier()` returns the configured `WebhookVerifier`.

**Always verify the RAW body bytes.** Never `json_decode()` and re-encode before verifying, and make sure no middleware (JSON normalisation, body parsers) rewrites the body first.

`headersFromGlobals()` reads `X-Project-Uuid`, `X-Timestamp` and `X-Signature` from `$_SERVER`. Framework header bags (Laravel, Symfony, PSR-7) can be passed as they are; header names are matched case-insensitively and may be arrays.

### Failure reasons

`WebhookVerificationException::$reason` is a `WebhookFailureReason`:

| Case | Meaning |
|---|---|
| `MissingHeader` | `X-Project-Uuid`, `X-Timestamp` or `X-Signature` is missing. |
| `ProjectMismatch` | The webhook is addressed to another project. |
| `TimestampOutOfTolerance` | The timestamp is malformed or older/newer than the tolerance. Check your server clock too. |
| `SignatureMismatch` | Wrong key, or the body was modified. |
| `MalformedPayload` | The signature is valid but the body is not a JSON object. |

After a valid signature `constructEvent()` never throws because of the event content: unknown types become `UnknownEvent`.

### Key rotation

During a payment-key rotation, accept both the new and the old key:

```php
$verifier = new WebhookVerifier($projectId, [new Secret($newKey), new Secret($oldKey)]);
```

A signature that matches any of the secrets is accepted. Drop the old key once the rotation is complete.

### Tolerance

The third constructor argument is the accepted `X-Timestamp` age in seconds (default 300); `ClientConfig::$webhookTolerance` sets it for `$enigmoss->webhooks`.

## Events

`constructEvent()` returns a `Enigmoss\Event\WebhookEvent`. The body is `{"event": "<object>.<status>", "event_id", "created_at", "data"}`.

| Class | `type` | Typed accessors |
|---|---|---|
| `PaymentEvent` | `payment.<status>`: an invoice or a static wallet deposit changed status | `payment(): Payment`, `status(): PaymentStatus` |
| `PayoutEvent` | `payout.<status>`: a payout created through the API changed status | `payout(): Payout`, `status(): PayoutStatus` |
| `RefundEvent` | `refund.<status>` | `refund(): Refund`, `status(): RefundStatus` |
| `TestEvent` | `webhook.test`, from `webhooks->sendTest()`; carries no object | none |
| `UnknownEvent` | a type this SDK version does not know | `data()` |

Common members of every event:

| Member | Meaning |
|---|---|
| `$id` | `event_id`, stable across retries and redeliveries. **Your deduplication key.** |
| `$type` | e.g. `payment.paid`. |
| `$createdAt` | `?DateTimeImmutable`, UTC. |
| `objectType()` | `WebhookObjectType`: `Payment`, `Wallet`, `Payout`, `Refund`, `Unknown`. |
| `statusName()` | The status part of the type, e.g. `paid`. |
| `data()` | The raw `data` array exactly as received (`null` for `webhook.test`). |

`Payment::isSuccessful()` (`status->isPaid() && isFinal`) is the one fulfilment rule: a paid payment can be non-final while an automatic conversion settles, so do not fulfil on `isPaid()` alone. See [Payments](payments.md#statuses-and-the-fulfilment-rule).

The typed object (`payment()`, `payout()`, `refund()`) is built lazily on first access, so an unknown field never breaks your endpoint before you can acknowledge it. It throws `UnexpectedResponseException` if the snapshot does not match this SDK version; catch it, log the raw `data()` and still answer 2xx if you cannot act on it.

```php
use Enigmoss\Event\PaymentEvent;
use Enigmoss\Event\PayoutEvent;
use Enigmoss\Event\RefundEvent;
use Enigmoss\Event\TestEvent;
use Enigmoss\Event\UnknownEvent;

switch (true) {
    case $event instanceof PaymentEvent:
        $payment = $event->payment();
        if ($payment->walletId !== null) {
            // a deposit to a static wallet: identify the customer by walletId
        } elseif ($payment->isSuccessful()) {
            // paid and final: fulfil $payment->orderId (idempotently)
        }
        break;
    case $event instanceof PayoutEvent:
        if ($event->payout()->isFinal) {
            // settle the withdrawal $event->payout()->orderId
        }
        break;
    case $event instanceof RefundEvent:
        // $event->refund()->status
        break;
    case $event instanceof TestEvent:
    case $event instanceof UnknownEvent:
    default:
        // acknowledge and ignore
        break;
}
```

Status values can be added in minor releases and then arrive as `...::Unknown`; keep a default branch.

## Idempotent handling

Because deliveries repeat and arrive out of order, make the handler idempotent:

1. **Deduplicate by `$event->id`, atomically.** Claim the id before you do anything; only one of two parallel deliveries wins. Release the claim if handling or queueing fails, so the next delivery is processed again.
2. **Do not trust order.** Compare `$event->createdAt`, or better re-read the object (`payments->get($id)`) before acting on a transition, and check `isFinal`.
3. **Act on state, not on events.** "Mark order paid if the payment is paid" is idempotent; "add 10.50 to the balance" is not.

`Enigmoss\Webhook\WebhookDeduplicatorInterface` is a two-method contract:

```php
interface WebhookDeduplicatorInterface
{
    public function claim(string $eventId): bool;   // atomic: true for the first caller, false if already claimed
    public function release(string $eventId): void; // forget a claim whose handling failed
}
```

The pattern:

```php
if (!$deduplicator->claim($event->id)) {
    http_response_code(200);   // already handled or being handled
    exit;
}

try {
    $queue->push($rawBody);    // or handle inline if it is fast
} catch (Throwable $e) {
    $deduplicator->release($event->id);   // let the next delivery try again
    throw $e;                             // answer non-2xx: the API retries
}

http_response_code(200);
```

Implementations:

| Implementation | Atomic | Notes |
|---|---|---|
| `Enigmoss\Webhook\PdoWebhookDeduplicator` | Yes: PostgreSQL, MySQL/MariaDB, SQLite | Your own database table. Use it in plain PHP and whenever the handler is not idempotent. |
| Laravel bridge (`CacheWebhookDeduplicator`) | Yes, on stores with an atomic `add` (redis, memcached, database, array) | Bound to the interface by default. |
| Symfony bridge (`CacheWebhookDeduplicator`, PSR-6) | **No**: two deliveries at the same instant can both claim | Bind `PdoWebhookDeduplicator` to the interface when the handler is not idempotent. See [Symfony](symfony.md#webhooks-mapenigmosswebhook). |

### PdoWebhookDeduplicator

```php
use Enigmoss\Webhook\PdoWebhookDeduplicator;

$deduplicator = new PdoWebhookDeduplicator($pdo);   // table "enigmoss_webhook_events"; pass a name as the second argument
```

Create the table once (the column types work on PostgreSQL, MySQL/MariaDB and SQLite):

```sql
CREATE TABLE enigmoss_webhook_events (
    event_id   VARCHAR(64) PRIMARY KEY,
    claimed_at TIMESTAMP   NOT NULL
);
```

Delete old rows with a periodic job (for example rows older than a few days): the API stops redelivering after about two days. If you claim on the same PDO connection inside your business transaction, a rollback drops the claim too.

When `$event->id` is an empty string there is nothing to claim: skip the claim and rely on an idempotent handler.

## Answer fast

The API waits 15 seconds. Verify, claim the event id, enqueue, answer. Do not call external services, send email or run long database work in the request. The Laravel bridge does this for you: it acknowledges at once and fires a `WebhookReceived` event from a queued job.

## Test it

- `$enigmoss->webhooks->sendTest($callbackUrl)` asks the API to send a `webhook.test` event to your URL (10 per minute): see [Webhook events API](webhook-events-api.md#test-webhooks).
- `WebhookSimulator` builds correctly signed requests for your own tests: see [Testing](testing.md#testing-webhook-endpoints).
- If you missed webhooks, replay them from the journal: [Webhook events API](webhook-events-api.md).

## Debug checklist

| Symptom | Likely cause |
|---|---|
| `signature_mismatch` | Wrong key (use the payment key), body modified before verification, or a proxy that re-encodes JSON. |
| `timestamp_out_of_tolerance` | Server clock drift, or the request is being replayed later. |
| `project_mismatch` | A webhook for a different project reached this endpoint; use one verifier per project. |
| Everything is rejected after a key rotation | Pass both the old and the new secret. |
| Webhooks time out | You do work inline; enqueue instead. |
