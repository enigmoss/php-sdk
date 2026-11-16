# Testing

The SDK ships helpers in `Enigmoss\Testing` so your tests never touch the network: `MockTransport`, `Fixtures` and `WebhookSimulator`. In Laravel, `Enigmoss::fake()` wires the mock transport into the facade.

## MockTransport

An in-memory `TransportInterface`. Queue responses, run your code, then inspect what the SDK sent.

```php
use Enigmoss\ClientConfig;
use Enigmoss\EnigmossClient;
use Enigmoss\Payment\CreatePaymentRequest;
use Enigmoss\Secret;
use Enigmoss\Testing\Fixtures;
use Enigmoss\Testing\MockTransport;
use PHPUnit\Framework\TestCase;

final class CheckoutTest extends TestCase
{
    public function testCreatesAnInvoice(): void
    {
        $transport = new MockTransport();
        $transport->queueData(Fixtures::payment(['order_id' => 'order-42']), 201);

        $enigmoss = new EnigmossClient(
            new ClientConfig(
                baseUrl: 'https://api.example.com',
                projectId: Fixtures::PROJECT_ID,
                paymentKey: new Secret('test-payment-secret'),
            ),
            $transport,
        );

        $payment = $enigmoss->payments->create(new CreatePaymentRequest(amount: '10.50', currencyCode: 'USDT', orderId: 'order-42'));

        self::assertSame('order-42', $payment->orderId);

        $request = $transport->lastRequest();
        self::assertSame('POST', $request?->method);
        self::assertSame('https://api.example.com/api/v4/payments', $request?->url);
        self::assertSame('order-42', $transport->lastJson()['order_id']);
        self::assertArrayHasKey('Idempotency-Key', $request?->headers ?? []);
    }
}
```

| Method | Purpose |
|---|---|
| `queue(HttpResponse\|TransportException\|Closure $response)` | Queue a raw response, a network failure to throw, or a `Closure(HttpRequest): HttpResponse` computed from the request. |
| `queueData(mixed $data, int $status = 200, array $meta = [], array $headers = [])` | Queue a successful envelope `{"data": ..., "meta": ...}`. |
| `queueError(int $status, string $type, string $code, string $message = 'Error.', bool $retryable = false, array $details = [], array $violations = [], ?int $retryAfter = null, array $headers = [])` | Queue an error envelope. |
| `requests()` | Every `HttpRequest` sent, in order. |
| `lastRequest()` | The last `HttpRequest`, or `null`. |
| `lastJson()` | `array`: the decoded JSON body of the last request (`[]` when it had no body). |
| `sent(string $method, string $path)` | `list<HttpRequest>`: requests with this method whose path starts with `$path`, e.g. `sent('POST', '/api/v4/payouts')`. Use it to assert that a repeated create sent the same `Idempotency-Key`, or that nothing was sent. |
| `pending()` | How many queued responses were not consumed. Assert it is `0` at the end. |

Responses are consumed in order, **one per attempt**, including automatic retries. A call with no queued response throws `LogicException`. `HttpRequest` exposes `method`, `url`, `headers`, `body`, `timeout`, `connectTimeout`.

### Lists

A list response carries the cursor in `meta`:

```php
$transport->queueData(
    [Fixtures::payment(['order_id' => 'a']), Fixtures::payment(['order_id' => 'b'])],
    meta: ['cursor' => ['next' => 'opaque-cursor', 'prev' => null, 'per_page' => 2, 'has_more' => true]],
);

$page = $enigmoss->payments->list();   // $page->hasMore === true, $page->nextCursor === 'opaque-cursor'
```

### Simulating errors

```php
use Enigmoss\Exception\BusinessRuleException;
use Enigmoss\Exception\ErrorCode;
use Enigmoss\Exception\ValidationException;

// A validation error with violations
$transport->queueError(
    422,
    'validation_error',
    ErrorCode::VALIDATION_FAILED,
    'The given data was invalid.',
    violations: [['field' => 'amount', 'rule' => 'required', 'message' => 'The amount field is required.']],
);

// A business error
$transport->queueError(422, 'business_error', ErrorCode::PAYOUT_INSUFFICIENT_FUNDS, 'Insufficient funds.');

// A rate limit with Retry-After
$transport->queueError(429, 'rate_limit_error', ErrorCode::RATE_LIMIT_EXCEEDED, 'Too many requests.', retryable: true, retryAfter: 1);
```

### Simulating network failures and unknown outcomes

```php
use Enigmoss\Exception\SendPhase;
use Enigmoss\Exception\TransportException;

$config = new ClientConfig(
    baseUrl: 'https://api.example.com',
    projectId: Fixtures::PROJECT_ID,
    payoutKey: new Secret('test-payout-secret'),
    maxRetries: 0,                       // see the failure instead of the retry
);
$enigmoss = new EnigmossClient($config, $transport);

// Timeout after the request was sent: the outcome is unknown.
$transport->queue(new TransportException('timeout', SendPhase::Sent, timedOut: true));

try {
    $enigmoss->payouts->create($request);
} catch (TransportException $e) {
    self::assertTrue($e->outcomeUnknown());
}

// Then the reconciliation finds the payout:
$transport->queueData([Fixtures::payout(['order_id' => $request->orderId])]);
$payout = $enigmoss->payouts->findByOrderId($request->orderId);
```

To exercise the [API origin fallback](configuration.md#api-origin-fallback), queue the failure the default transport reports when the API host cannot be reached, then the signed answer of the fallback host and the response of the new origin:

```php
use Enigmoss\Exception\ConnectionException;
use Enigmoss\Http\HttpRequest;
use Enigmoss\Http\HttpResponse;

$config = new ClientConfig(
    baseUrl: 'https://api.example.com',
    projectId: Fixtures::PROJECT_ID,
    paymentKey: new Secret('test-payment-secret'),
    maxRetries: 0,
    fallbackBaseUrl: 'https://api.example.org',
);
$enigmoss = new EnigmossClient($config, $transport);

$transport->queue(new ConnectionException('could not resolve host', null, hostUnreachable: true));
$transport->queue(static function (HttpRequest $request): HttpResponse {
    $body = json_encode(['data' => Fixtures::platformBrand(['api' => ['origin' => 'https://api2.example.com']])], JSON_UNESCAPED_SLASHES);
    $timestamp = $request->headers['X-Timestamp'];

    return new HttpResponse(200, [
        'X-Response-Timestamp' => $timestamp,
        'X-Response-Signature' => hash_hmac('sha256', $timestamp . "\n" . $request->headers['X-Signature'] . "\n" . $body, 'test-payment-secret'),
    ], $body);
});
$transport->queueData(Fixtures::project());

$enigmoss->project->get();
self::assertSame('https://api2.example.com/api/v4/project', $transport->lastRequest()?->url);
self::assertSame('https://api2.example.com', $enigmoss->config()->origin);
```

### Testing retries without sleeping

`EnigmossClient` accepts a `SleeperInterface` (and a `ClockInterface`). Pass a no-op sleeper so retry tests are instant:

```php
use Enigmoss\Retry\SleeperInterface;

$sleeper = new class implements SleeperInterface {
    public float $slept = 0.0;

    public function sleep(float $seconds): void
    {
        $this->slept += $seconds;
    }
};

$enigmoss = new EnigmossClient($config, $transport, sleeper: $sleeper);

$transport->queueError(429, 'rate_limit_error', 'rate_limit.exceeded', retryAfter: 1, retryable: true);
$transport->queueData(Fixtures::project());

$enigmoss->project->get();   // retried once; $sleeper->slept === 1.0
```

## Fixtures

`Enigmoss\Testing\Fixtures` returns realistic payloads in the **wire format** (snake_case keys, wire enum values such as `'paid'` or `'underpaid_waiting'`). Every factory takes `$overrides`, merged recursively: nested objects are merged key by key, lists are replaced as a whole.

```php
Fixtures::payment(['status' => 'paid', 'is_final' => true, 'paid' => ['amount' => '10.50']]);
```

**All `id` arguments you pass to the SDK must be UUIDs.** They are validated locally: `$enigmoss->refunds->get('refund-1')` throws `InvalidArgumentException` before any request. Use the fixture ids or your own UUIDs. `order_id` values are free-form (1-128 letters, digits, `-`, `_`).

| Factory | Signature | Overridable keys (default) |
|---|---|---|
| `Fixtures::PROJECT_ID` | constant | A valid project UUID. |
| `project` | `project(array $overrides = [])` | `id`, `name` (`Example Shop`), `key_type` (`payment`), `permissions` (list), `server_time` (int) |
| `payment` | `payment(array $overrides = [])` | `id`, `order_id` (`order-42`), `wallet_id` (null), `status` (`pending`), `is_final` (false), `failure_code` (null), `amount` (`10.50`), `currency_code` (`USDT`), `payer` {`amount`, `currency_code`, `network_code`, `address`, `sender_address`, `exchange_rate`}, `paid` {`amount`, `amount_usd`}, `settlement` {`amount`, `currency_code`, `fee`}, `conversion` (null), `discount` {`percent`, `amount`}, `tx_hash`, `payment_url`, `expires_at`, `created_at`, `updated_at`, `paid_at`, `description`, `metadata` |
| `paidPayment` | `paidPayment(array $overrides = [])` | A complete paid invoice: same keys as `payment`, with `status` `paid`, `is_final` true and filled `payer`, `paid`, `settlement`, `tx_hash`, `paid_at`. For a deposit use `['order_id' => null, 'wallet_id' => '<uuid>']`. |
| `wallet` | `wallet(array $overrides = [])` | `id`, `order_id` (`customer-7`), `currency_code`, `network_code`, `address`, `status` (`active`), `refund_incoming` (false), `callback_url`, `payment_url`, `created_at`, `updated_at` |
| `refund` | `refund(array $overrides = [])` | `id`, `order_id` (`refund-42`), `source` (`payment`), `payment_id`, `wallet_id`, `status` (`pending`), `is_final`, `failure_code`, `amount`, `currency_code`, `network_code`, `address`, `tx_hash`, `created_at`, `updated_at`, `completed_at` |
| `payout` | `payout(array $overrides = [])` | `id`, `order_id` (`withdrawal-42`), `status` (`pending`), `is_final`, `failure_code`, `amount`, `currency_code`, `network_code`, `address`, `memo`, `priority` (`recommended`), `recipient` {`amount`, `currency_code`}, `charged` {`amount`, `currency_code`, `fee`}, `conversion`, `tx_hash`, `created_at`, `updated_at` |
| `payoutQuote` | `payoutQuote(array $overrides = [])` | `amount`, `currency_code`, `network_code`, `priority`, `recipient`, `charged`, `conversion` |
| `transfer` | `transfer(array $overrides = [])` | `id`, `order_id` (`transfer-1`), `amount`, `currency_code`, `transaction_id`, `created_at` |
| `transaction` | `transaction(array $overrides = [])` | `id`, `type` (`payment`), `amount`, `fee`, `currency_code`, `network_code`, `payment_id`, `payout_id`, `refund_id`, `transfer_id`, `created_at` |
| `balance` | `balance(array $overrides = [])` | `currency_code`, `balance`, `balance_usd` |
| `webhook` | `webhook(string $event, ?array $data, string $eventId = '019b7710-0000-7000-8000-000000000001', string $createdAt = '2026-01-01T00:05:00Z')` | Builds the envelope `{event, event_id, created_at, data}`. Pass a different `$eventId` for each event you post: events with the same id are duplicates. `$data` is `null` for `webhook.test`. |
| `eventRecord` | `eventRecord(array $overrides = [])` | `id`, `event`, `object_type`, `object_id`, `delivery_status`, `created_at`, `payload` (a `webhook()` envelope) |

```php
Fixtures::webhook('payout.completed', Fixtures::payout(['status' => 'completed', 'is_final' => true]), eventId: '019b7710-0000-7000-8000-000000000002');
```

## Testing webhook endpoints

`WebhookSimulator::sign()` builds a request body with valid `X-Project-Uuid`, `X-Timestamp` and `X-Signature` headers.

```php
use Enigmoss\Secret;
use Enigmoss\Testing\Fixtures;
use Enigmoss\Testing\WebhookSimulator;
use Enigmoss\Webhook\WebhookVerifier;

$webhook = WebhookSimulator::sign(
    Fixtures::webhook('payment.paid', Fixtures::paidPayment()),   // an envelope to encode, or an exact string body
    Fixtures::PROJECT_ID,
    new Secret('test-payment-secret'),
    timestamp: null,                                              // defaults to now
);

$webhook->body;      // the exact JSON string
$webhook->headers;   // Content-Type, X-Project-Uuid, X-Timestamp, X-Signature

$verifier = new WebhookVerifier(Fixtures::PROJECT_ID, new Secret('test-payment-secret'));
$event = $verifier->constructEvent($webhook->body, $webhook->headers);
```

**Send the exact `$webhook->body` string to your endpoint.** Signatures cover the raw bytes, so a test helper that decodes and re-encodes the JSON (for example Laravel's `postJson()`, which escapes `/` by default) can change the bytes and fail verification.

`$webhook->serverVariables()` returns the headers as PHP server variables (`HTTP_X_PROJECT_UUID`, `HTTP_X_TIMESTAMP`, `HTTP_X_SIGNATURE`, `CONTENT_TYPE`), ready for framework test clients:

```php
// Laravel
$this->call('POST', '/webhooks/enigmoss', [], [], [], $webhook->serverVariables(), $webhook->body);

// Symfony (KernelBrowser)
$client->request('POST', '/webhooks/enigmoss', [], [], $webhook->serverVariables(), $webhook->body);
```

In Laravel there is a shortcut: the `Enigmoss\Bridge\Laravel\Testing\InteractsWithEnigmossWebhooks` trait provides `postEnigmossWebhook($event, $data, $uri = '/webhooks/enigmoss', $connection = null, $eventId = ...)`: see [Laravel testing](laravel.md#webhook-endpoint-tests).

To test a forged webhook, sign with a different secret, or tamper with the body after signing.

## Laravel: Enigmoss::fake()

```php
use Enigmoss\Bridge\Laravel\Facades\Enigmoss;
use Enigmoss\Payment\CreatePaymentRequest;
use Enigmoss\Testing\Fixtures;

public function test_it_creates_an_invoice(): void
{
    $transport = Enigmoss::fake();                 // returns the MockTransport
    $transport->queueData(Fixtures::payment(), 201);

    $payment = Enigmoss::payments()->create(new CreatePaymentRequest('10.50', 'USDT', 'order-42'));

    $this->assertSame('order-42', $payment->orderId);
    $this->assertSame('https://api.example.com/api/v4/payments', $transport->lastRequest()?->url);
}
```

`fake()` replaces the HTTP transport of **every** connection with one `MockTransport` and drops cached clients, so call it before the code under test uses a client. It works without any `ENIGMOSS_*` configuration: missing `base_url`, `project_id` and keys are filled with placeholders, and no real request is made. Assert with `$transport->lastJson()` and `$transport->sent('POST', '/api/v4/payments')`.

More Laravel examples: [Laravel testing](laravel.md#testing).

## Symfony

Use `transport: symfony` on the client and Symfony's `MockHttpClient` as the `http_client` in the test environment, or override the `enigmoss.client.<name>` service with a client built around a `MockTransport`. See [Symfony](symfony.md#testing).

## Suggested checks for your own code

- A repeated create with the same `order_id` sends the same `Idempotency-Key` (read it from `$transport->requests()`).
- Your handler survives `outcomeUnknown()` (queue a `TransportException` with `SendPhase::Sent`).
- Your webhook endpoint returns 400 for a forged signature, 200 for a valid one and 200 again for a duplicate event id. Also test the failure path: when queueing throws, the claim is released and a redelivery is handled.
- A payout flow with an unknown outcome ends as pending reconcile and does not create a second payout: queue a `TransportException` with `SendPhase::Sent`, assert `count($transport->sent('POST', '/api/v4/payouts')) === 1`.
- Your fulfilment code uses `Payment::isSuccessful()` and ignores a paid payment that is not final yet (`Fixtures::paidPayment(['is_final' => false])`).
- Your handler ignores `UnknownEvent` and `TestEvent` without failing.
