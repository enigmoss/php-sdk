# Enigmoss PHP SDK

The official PHP SDK for the Enigmoss Project API v4: accept crypto payments, run static wallets, send payouts and refunds, and receive signed webhooks, with typed objects and money-safe retries.

[![Packagist Version](https://img.shields.io/packagist/v/enigmoss/enigmoss-php.svg)](https://packagist.org/packages/enigmoss/enigmoss-php)
[![PHP >= 8.1](https://img.shields.io/badge/php-%3E%3D8.1-777bb4.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

- **Typed DTOs.** Requests, responses, enums and webhook events are real classes with real types. No arrays-of-arrays.
- **Zero runtime dependencies.** Only `ext-json`, plus `ext-curl` for the default transport. Bring your own HTTP client through PSR-18 or Symfony HttpClient if you prefer.
- **Money-safe retries and idempotency.** Create calls derive their `Idempotency-Key` from your `order_id`; the SDK never retries a money-moving call in a way that could pay twice. A create ends in one of three outcomes (created, definitely not created, pending reconcile): see [Errors](docs/errors.md#the-three-outcomes-of-a-money-creating-call).
- **Webhook verification.** HMAC-SHA256 signature check, replay window, key rotation, typed events and an atomic deduplicator (`claim()` / `release()`, including a PDO implementation).
- **Laravel and Symfony bridges.** Auto-discovered service provider, facade, webhook route and artisan commands; a Symfony bundle with autowiring and a controller argument resolver.
- **Testing helpers.** `MockTransport`, `Fixtures`, `WebhookSimulator`, `Enigmoss::fake()`, and examples that run offline.

## Requirements and supported versions

| Component | Supported versions |
|---|---|
| PHP | 8.1, 8.2, 8.3, 8.4, 8.5 |
| Laravel (optional bridge) | 10, 11, 12, 13 |
| Symfony (optional bridge) | 6.4, 7.4, 8.x |

Extensions: `ext-json` (always), `ext-curl` (default transport only).

**PHP-version policy.** A PHP version is dropped only in a major release of the SDK, never in a minor or patch release. See [Versioning and BC](docs/versioning-and-bc.md).

## Install

```bash
composer require enigmoss/enigmoss-php
```

## Quickstart

The API base URL is white-label: use "your API host from the dashboard". `https://api.example.com` below is a placeholder.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Enigmoss\ClientConfig;
use Enigmoss\EnigmossClient;
use Enigmoss\Payment\CreatePaymentRequest;
use Enigmoss\Secret;

$enigmoss = new EnigmossClient(new ClientConfig(
    baseUrl: 'https://api.example.com',
    projectId: '3f2b8c1e-5d4a-4e7b-9c6f-1a2b3c4d5e6f',
    paymentKey: new Secret((string) getenv('ENIGMOSS_PAYMENT_KEY')),
));

$payment = $enigmoss->payments->create(new CreatePaymentRequest(amount: '10.50', currencyCode: 'USDT', orderId: 'order-42'));

if ($payment->paymentUrl === null) {
    // may be null: do not redirect to an empty URL
    http_response_code(502);
    exit;
}

header('Location: ' . $payment->paymentUrl);
```

Amounts are always decimal **strings** (`"10.50"`), never floats.

## Webhook endpoint quickstart

Webhooks are signed with your **payment key**. Verify the **raw** body, answer 2xx quickly, and do heavy work in a queue.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Enigmoss\Event\PaymentEvent;
use Enigmoss\Exception\WebhookVerificationException;
use Enigmoss\Secret;
use Enigmoss\Webhook\WebhookVerifier;

$verifier = new WebhookVerifier('3f2b8c1e-5d4a-4e7b-9c6f-1a2b3c4d5e6f', new Secret((string) getenv('ENIGMOSS_PAYMENT_KEY')));

try {
    $event = $verifier->constructEvent((string) file_get_contents('php://input'), WebhookVerifier::headersFromGlobals());
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    exit;
}

if ($event instanceof PaymentEvent) {
    $payment = $event->payment();

    if ($payment->walletId !== null) {
        // A deposit to a static wallet: there is no orderId. Identify the customer by walletId.
    } elseif ($payment->isSuccessful()) {
        // Paid and final (status->isPaid() && isFinal): claim $event->id for deduplication,
        // then enqueue fulfilment of $payment->orderId.
    }
}

http_response_code(200);
```

`isSuccessful()` is the one fulfilment rule: a paid payment can be non-final while an automatic conversion settles. Deduplicate with `WebhookDeduplicatorInterface::claim()` (atomic); [Webhooks](docs/webhooks.md#idempotent-handling) shows the pattern and the `PdoWebhookDeduplicator`.

A complete version is in [`examples/webhook-endpoint.php`](examples/webhook-endpoint.php). All examples can run offline: `ENIGMOSS_EXAMPLE_MOCK=happy php examples/create-payment.php` (see [examples](examples/README.md)).

## Documentation

**Start here**

- [Getting started](docs/getting-started.md): install, first request, first webhook, and why there is no sandbox.
- [Configuration](docs/configuration.md): every `ClientConfig` option, `Secret`, multiple projects, transports.
- [Authentication and keys](docs/authentication-and-keys.md): payment key vs payout key, which keys a scenario needs, endpoint-to-permission matrix.

**Resources**

- [Payments](docs/payments.md): invoices, statuses, cancel, mark paid, AML links.
- [Wallets](docs/wallets.md): static wallets and their deposits.
- [Refunds](docs/refunds.md): refund a payment or a blocked wallet.
- [Payouts](docs/payouts.md): the quote, create, reconcile flow.
- [Balances and transfers](docs/balances-and-transfers.md): balances, transactions, transfers to the personal account.
- [Catalog](docs/catalog.md): payment methods, payout methods, exchange rates.

**Webhooks**

- [Webhooks](docs/webhooks.md): verification, events, delivery rules, deduplication.
- [Webhook events API](docs/webhook-events-api.md): the events journal, redelivery and replay.

**Reliability**

- [Errors](docs/errors.md): exception hierarchy, error codes, violations, the `outcomeUnknown()` table, the three outcomes and the catch order.
- [Idempotency and retries](docs/idempotency-and-retries.md): how the SDK keeps money calls safe.
- [Pagination](docs/pagination.md): `list()`, `iterate()`, `findByOrderId()`, cursors.

**Frameworks and tooling**

- [Laravel](docs/laravel.md)
- [Symfony](docs/symfony.md)
- [Testing](docs/testing.md)
- [Custom HTTP client](docs/custom-http-client.md)
- [Logging and observability](docs/logging-and-observability.md)

**Project**

- [Security](docs/security.md) and [SECURITY.md](SECURITY.md)
- [Versioning and BC](docs/versioning-and-bc.md)
- [Changelog](CHANGELOG.md), [Upgrade guide](UPGRADE.md), [Contributing](CONTRIBUTING.md)
- [`docs/llms.txt`](docs/llms.txt): a compact API summary for AI coding assistants
- [Runnable examples](examples/README.md)

## License

MIT. See [LICENSE](LICENSE).
