# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0]

Stable release of the Enigmoss Project API v4 SDK.

## [0.1.0]

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

- API origin fallback: `ClientConfig(fallbackBaseUrl: ...)`. When the API host cannot be reached at all (DNS failure, refused connection, failed TLS handshake, connect timeout) and the usual retries did not help, the client sends one signed `GET /api/v4/project/platform-brand` to the fallback host, takes `data.api.origin` from the answer and starts the same request over on that origin, with the same body and the same `Idempotency-Key`. The order is always retries first, fallback second; a timeout of the response, a connection lost after sending and a proxy failure never start it. The answer is trusted only with a valid `X-Response-Signature` (HMAC-SHA256 of the response timestamp, the signature of the request and the body, with the key of the request; the request carries a one-time nonce) and a plain public https origin. The origin is remembered for `refresh_after` seconds (one hour to seven days). An error of the fallback host is thrown with the original network error as `getPrevious()` and remembers nothing; after a fallback without a new origin the fallback host is not asked for 5 minutes. Without `fallbackBaseUrl` nothing changes. The fallback host of the Enigmoss production API is `https://tavrion.org`; the SDK has no default.
- `OriginStoreInterface` and `ClientConfig(originStore: ...)`: share the learned origin between PHP processes (under PHP-FPM a client lives for one request). Adapters: `Bridge\Psr16\Psr16OriginStore` (any PSR-16 cache), `Bridge\Laravel\CacheOriginStore` and `Bridge\Symfony\Http\CacheOriginStore`. Without a store the origin lives in the memory of the client. Stored records are authenticated with an HMAC of the project key: write access to the cache alone cannot move a client to another host.
- The fallback starts only with `CurlTransport`, which reports the new `TransportException::$hostUnreachable` (`ConnectionException` takes it as a third argument); behind an outgoing proxy only a failed DNS lookup of the API host counts. **`Psr18Transport` and `SymfonyHttpClientTransport` never start it**: they cannot tell whether a connection was established. Custom transports opt in by setting `hostUnreachable`.
- **New enum case `ClientEventType::OriginChanged`**, emitted when the client moves to the origin named by the fallback host, with the new `ClientEvent::$origin` and `ClientEvent::$previousOrigin`. A `match` over `ClientEventType` without a `default` arm must get one (see [Versioning and BC](docs/versioning-and-bc.md#enums-can-grow)). `LoggerObserver` logs the move as a warning.
- `$enigmoss->config()->origin` is the API origin in use right now; `ClientConfig::$fallbackOrigin`; `enigmoss:check` (Laravel and Symfony) prints the API origin and the fallback host.
- `PlatformBrand::$apiOrigin`: the API origin of the project as reported by `project->platformBrand()` (`null` when the server does not send it).
- Laravel: `ENIGMOSS_FALLBACK_BASE_URL` / `connections.*.fallback_base_url`, with the learned origin kept in the default cache store. Symfony: `clients.*.fallback_base_url` and `origin_cache` (default `cache.app`).

### Changed

- **The Composer package is `enigmoss/php-sdk`** (it was `enigmoss/enigmoss-php`): `composer require enigmoss/php-sdk`. Namespaces, class names and the `User-Agent` are unchanged.
- Examples and documentation check `outcomeUnknown()` before `isOrderIdTaken()`, so `idempotency.in_progress` is never reported as "order_id taken".
- `IpNotAllowedException` is thrown only for `auth.ip_forbidden`.
- `Psr18Transport` no longer reports a PSR-18 `RequestExceptionInterface` as "not sent" (`ConnectionException`): clients such as Guzzle throw it for failures in the middle of a transfer too. Every PSR-18 failure is now a `TransportException` with `SendPhase::Unknown`, so a money-moving request is repeated only when an `Idempotency-Key` protects it.

### Fixed

- `CurlTransport` with libcurl 8: a connect timeout - a TCP connect or a TLS handshake that never finishes, which is what a blocked API host looks like - was reported as "sent" (`TransportException`, `SendPhase::Sent`), because libcurl 8 sets the pre-transfer time of a failed connect to the moment of the failure instead of zero. It is a `ConnectionException` again, so a money-moving request is retried and the API origin fallback starts. A timeout now counts as "not sent" only when all signs agree: curl counted no request bytes, the connection was not established (no finished TLS handshake for https, no TCP connect for http), and either the transfer never got ready or curl itself reports a timeout of the resolve or connect phase. A request that was sent on a reused connection stays "sent", also with libcurl 8.7, which reports a request size of zero for it.
