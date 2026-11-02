# Getting started

## 1. Install

```bash
composer require enigmoss/enigmoss-php
```

PHP 8.1 or newer and `ext-json` are required. The default HTTP transport uses `ext-curl`; if you cannot use curl, see [Custom HTTP client](custom-http-client.md) and the PSR-18 and Symfony transports in [Configuration](configuration.md#transports).

## 2. Collect your credentials

From the dashboard you need:

| Value | Used for |
|---|---|
| **API host**, e.g. `https://api.example.com` | `baseUrl`. The host is white-label and specific to your account. |
| **Project ID** (a UUID) | `projectId`, sent as `X-Project-Uuid`. |
| **Payment key** | Payments, wallets, balances, transactions, the events journal, test webhooks, and verifying incoming webhooks. |
| **Payout key** | Payouts, payout quotes, refunds and transfers. Pass it only if you send money out. |

Pass only the keys you use. See [Authentication and keys](authentication-and-keys.md) for which endpoint needs which key, and for which keys a whole scenario needs (for example, `payout.*` webhooks are verified with the **payment** key).

### Is there a sandbox or test mode?

There is **no documented sandbox or test mode**: the API has no test keys, and the SDK cannot tell a test project from a live one. Be careful with anything that moves money (`payouts->create()`, `refunds->createFor*()`, `transfers->create()`). Recommended way to develop:

- **Offline, no network:** run the [examples](../examples/README.md) with `ENIGMOSS_EXAMPLE_MOCK=<scenario>`, and use `MockTransport` and `Fixtures` in your own tests ([Testing](testing.md)). Nothing leaves your machine.
- **Against the real API:** use a **dedicated project just for testing**, fund it with small amounts and keep its keys out of production. Use the permission list returned by `project->get()` to see what the key can do.

## 3. Create the client

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Enigmoss\ClientConfig;
use Enigmoss\EnigmossClient;
use Enigmoss\Secret;

$enigmoss = new EnigmossClient(new ClientConfig(
    baseUrl: 'https://api.example.com',
    projectId: '3f2b8c1e-5d4a-4e7b-9c6f-1a2b3c4d5e6f',
    paymentKey: new Secret((string) getenv('ENIGMOSS_PAYMENT_KEY')),
    payoutKey: new Secret((string) getenv('ENIGMOSS_PAYOUT_KEY')),   // optional
));
```

`Secret` wraps a key so it never appears in `var_dump()`, logs, stack traces or serialized payloads. Always wrap keys in it. An empty string is rejected, so check your environment variable is set.

The client is stateless apart from a clock-drift correction, so one instance can be shared by the whole application, including long-running workers (Octane, Swoole, queue workers). It cannot be serialized: resolve it inside the job instead of passing it to the queue.

## 4. Check the credentials

```php
$project = $enigmoss->project->get();

echo $project->name, ' (', $project->keyType->value, ")\n";
echo implode(', ', $project->permissions), "\n";
echo 'Server time: ', $project->serverTime, "\n";
```

`project->get()` works with either key. It returns the project, which key signed the request, the permissions that key can use, and the server time, which is handy for spotting a drifting clock. Requests are signed with HMAC-SHA256 automatically; the server accepts a 300 second clock window, and the SDK corrects drift once automatically using the server time.

## 5. Create an invoice

```php
use Enigmoss\Payment\CreatePaymentRequest;

$payment = $enigmoss->payments->create(new CreatePaymentRequest(
    amount: '10.50',
    currencyCode: 'USDT',
    orderId: 'order-42',
    callbackUrl: 'https://shop.example/webhooks/enigmoss',
));

if ($payment->paymentUrl === null) {
    // may be null: do not redirect to an empty URL; reconcile with payments->get($payment->id)
    http_response_code(502);
    exit;
}

header('Location: ' . $payment->paymentUrl);
```

Three rules to remember from day one:

1. **Money is a decimal string.** `'10.50'`, never `10.5`. Floats are rejected.
2. **`orderId` is your idempotency anchor.** One `order_id` is one object forever. Repeating the same `create()` returns the stored response for 24 hours instead of creating a second invoice. See [Idempotency and retries](idempotency-and-retries.md).
3. **Dates are UTC.** All `DateTimeImmutable` values in responses are in UTC.

## 6. Receive the result by webhook

When the customer pays, the API sends a signed `payment.paid` webhook to your `callbackUrl`.

```php
use Enigmoss\Event\PaymentEvent;
use Enigmoss\Exception\WebhookVerificationException;
use Enigmoss\Webhook\WebhookVerifier;

try {
    $event = $enigmoss->webhooks->constructEvent(
        (string) file_get_contents('php://input'),
        WebhookVerifier::headersFromGlobals(),
    );
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    exit;
}

if ($event instanceof PaymentEvent) {
    $payment = $event->payment();

    if ($payment->walletId !== null) {
        // a deposit to a static wallet: there is no orderId, identify the customer by walletId
    } elseif ($payment->isSuccessful()) {
        // paid and final: fulfil $payment->orderId (deduplicate by $event->id first)
    }
}

http_response_code(200);
```

Read [Webhooks](webhooks.md) before going live: deduplication, ordering, the 15 second limit and key rotation matter.

## 7. Handle errors

Every failure is an exception implementing `Enigmoss\Exception\EnigmossException`:

```php
use Enigmoss\Exception\EnigmossException;

try {
    $payment = $enigmoss->payments->create($request);
} catch (EnigmossException $e) {
    if ($e->outcomeUnknown()) {
        // The invoice may exist. Look it up with payments->findByOrderId() (later, if it is not there yet);
        // never create a new order_id for the same order.
        return;
    }
    throw $e;   // not created: fix the cause, the same order_id may be used again
}
```

See [Errors](errors.md) for the full hierarchy, the three outcomes of a create (created, definitely not created, pending reconcile) and the catch order.

## Next steps

- [Payments](payments.md), [Wallets](wallets.md), [Payouts](payouts.md)
- [Testing](testing.md): `MockTransport`, `Fixtures`, `WebhookSimulator`
- [Laravel](laravel.md) or [Symfony](symfony.md) if you use a framework
- [Runnable examples](../examples/README.md)
