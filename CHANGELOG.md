# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `EnigmossClient` with services for the Project API v4: `project`, `payments`, `wallets`, `refunds`, `payouts`, `payoutMethods`, `paymentMethods`, `exchangeRates`, `balances`, `transactions`, `transfers`, `events` and `webhooks`.
- Typed request objects, query objects, response models and enums with an `Unknown` case for forward compatibility.
- Automatic HMAC-SHA256 request signing with the payment key and the payout key, and one-time clock-drift correction from the server time.
- Money-safe idempotency: the `Idempotency-Key` of create calls is derived from the `order_id`; safe automatic retries with exponential backoff and jitter; money-creating calls never retry business errors.
- Typed exception hierarchy with `outcomeUnknown()`, `ErrorCode` constants, `ErrorType`, violations and `SendPhase`.
- Cursor pagination with `list()`, lazy `iterate()` and `findByOrderId()`.
- Webhook verification (`WebhookVerifier`, key rotation, replay window), typed events (`PaymentEvent`, `PayoutEvent`, `RefundEvent`, `TestEvent`, `UnknownEvent`) and `WebhookDeduplicatorInterface`.
- Webhook events journal: list, inspect, redeliver, bulk redeliver and test webhooks.
- `EnigmossClient::request()` for endpoints the SDK does not model yet.
- `Secret` to keep API keys out of dumps, logs and serialized payloads.
- Transports: `CurlTransport` (default, no dependencies), `Psr18Transport` and `SymfonyHttpClientTransport`, and the `TransportInterface` contract for custom clients.
- Observability: `ObserverInterface`, `ClientEvent`, a PSR-3 `LoggerObserver` and `RequestOptions(onResponse: ...)`.
- Testing helpers: `MockTransport`, `Fixtures` and `WebhookSimulator`.
- Laravel bridge (Laravel 10 to 13): auto-discovered service provider, `Enigmoss` facade with `fake()`, multiple connections, `Route::enigmossWebhooks()`, queued `WebhookReceived` event, `enigmoss:check` and `enigmoss:webhook-test` commands, log channel support.
- Symfony bridge (Symfony 6.4, 7.4 and 8.x): `EnigmossBundle`, named client autowiring, `#[MapEnigmossWebhook]`, a cache-backed deduplicator, console commands and an opt-in `symfony` transport.
- Documentation in `docs/`, runnable scripts in `examples/` and `docs/llms.txt` for AI coding assistants.
- `ConflictException::isOrderIdTaken()`: true when a create failed because the `order_id` already belongs to an object; false for `IdempotencyException`, busy locks and state conflicts.
- `project->platformBrand()`: the brand to show payers (name, logo with its SHA-256, payment form origin) and how long to cache it.
- `Payment::isSuccessful()` (`status->isPaid() && isFinal`): the canonical fulfilment rule.
- Exception messages now read `"<server message> [<code>, request <id>] <hint>"`; the raw server text is `ApiException::$serverMessage`. Webhook verification messages name the missing header or point to the raw body and the payment key.
- Atomic webhook deduplication: `WebhookDeduplicatorInterface` is now `claim(string): bool` and `release(string): void` (replacing the earlier non-atomic check-then-mark contract), and `PdoWebhookDeduplicator` works on PostgreSQL, MySQL/MariaDB and SQLite. The Laravel bridge claims with an atomic cache `add`, releases the claim when queueing fails (the endpoint answers 500) and when the queued job fails for good. The Symfony bridge deduplicator (PSR-6) is not atomic; bind `PdoWebhookDeduplicator` when the handler is not idempotent.
- Testing helpers: `MockTransport::lastJson()` and `MockTransport::sent()`, `SignedWebhook::serverVariables()`, the Laravel trait `InteractsWithEnigmossWebhooks` (`postEnigmossWebhook()`), and `Enigmoss::fake()` without any `ENIGMOSS_*` configuration.
- Examples run offline with `ENIGMOSS_EXAMPLE_MOCK=<scenario>` (`happy`, `order_taken`, `in_progress`, `unknown_found`, `unknown_pending`); the money examples exit with code 3 for "pending reconcile". `tests/Examples/ExamplesTest.php` runs them in CI and `composer test` runs the suites `unit`, `examples` and `bridge`.
- Symfony commands `enigmoss:check` and `enigmoss:webhook-test` accept `--client=<name>`.
- Documentation: the three-outcome model (created, definitely not created, pending reconcile) with the catch order and a cron reconcile snippet; "HTTP status and error code" tables for every create endpoint; status tables with `isFinal` for payments, payouts and refunds; a Laravel payout job recipe (timeouts, `retry_after`, `ShouldBeUnique`); retrying payment for an order; a wallet `findOrCreate` recipe; the table of `Fixtures` factories; "which keys a scenario needs"; an honest note that there is no sandbox mode; and an extended `llms.txt` with the public classes.

### Changed

- Examples and documentation check `outcomeUnknown()` before `isOrderIdTaken()`, so `idempotency.in_progress` is never reported as "order_id taken".
- `IpNotAllowedException` is thrown only for `auth.ip_forbidden`.
