# Logging and observability

## ObserverInterface

Pass observers as the fifth constructor argument of `EnigmossClient`. They receive an event for every attempt, retry and failure.

```php
interface ObserverInterface
{
    public function onEvent(ClientEvent $event): void;
}
```

Observers **must not throw**: any exception they raise is swallowed so an observer can never break an API call.

```php
use Enigmoss\EnigmossClient;

$enigmoss = new EnigmossClient($config, observers: [$observer]);
```

### ClientEvent

A `ClientEvent` never contains bodies, headers or secrets.

| Property | Meaning |
|---|---|
| `type` | `ClientEventType`: `RequestSending`, `ResponseReceived`, `RetryScheduled`, `RequestFailed`, `OriginChanged`. |
| `method` | HTTP method. |
| `path` | Request path with the query, starting with `/api/v4`. |
| `attempt` | 1-based attempt number. |
| `statusCode` | HTTP status, when a response arrived. |
| `requestId` | The API request id: quote it to support. |
| `durationSeconds` | Duration of the attempt (`ResponseReceived`). |
| `retryDelaySeconds` | Delay before the next attempt (`RetryScheduled`). |
| `error` | The `Throwable` behind a retry or a failure; for `OriginChanged`, the network error that started the fallback. |
| `origin` | The API origin in use from now on (`OriginChanged`). |
| `previousOrigin` | The API origin that could not be reached (`OriginChanged`). |

The sequence for one call: `RequestSending`, then `ResponseReceived` or a thrown transport failure; on a retryable failure `RetryScheduled` and another `RequestSending`; and `RequestFailed` when the call finally fails (the exception is then thrown to you).

`OriginChanged` is emitted when the [API origin fallback](configuration.md#api-origin-fallback) moves the client to another host: once, in the middle of the call that found it, followed by `RequestSending` with `attempt` 1 on the new origin. The lookup on the fallback host itself emits no events. Alert on it: it means the configured API host is unreachable from your servers.

`ClientEventType` can gain cases in a minor release, as `OriginChanged` did: **keep a `default` arm** in a `match` over it.

### A custom observer

```php
use Enigmoss\Http\ClientEvent;
use Enigmoss\Http\ClientEventType;
use Enigmoss\Http\ObserverInterface;

final class MetricsObserver implements ObserverInterface
{
    public function __construct(private readonly \Closure $increment, private readonly \Closure $timing) {}

    public function onEvent(ClientEvent $event): void
    {
        match ($event->type) {
            ClientEventType::ResponseReceived => ($this->timing)('enigmoss.request', (float) $event->durationSeconds, ['status' => (string) $event->statusCode]),
            ClientEventType::RetryScheduled => ($this->increment)('enigmoss.retry'),
            ClientEventType::RequestFailed => ($this->increment)('enigmoss.failure'),
            ClientEventType::OriginChanged => ($this->increment)('enigmoss.origin_changed'),
            default => null,
        };
    }
}
```

## PSR-3 logging: LoggerObserver

`Enigmoss\Bridge\Psr3\LoggerObserver` logs SDK calls to any PSR-3 logger (`psr/log` must be installed). Bodies, headers and keys are never logged.

```php
use Enigmoss\Bridge\Psr3\LoggerObserver;
use Enigmoss\EnigmossClient;

$enigmoss = new EnigmossClient($config, observers: [new LoggerObserver($logger)]);
```

| Event | Level | Message |
|---|---|---|
| `ResponseReceived` | `debug` | `Enigmoss API response` |
| `RetryScheduled` | `notice` | `Enigmoss API call retried` |
| `RequestFailed` | `warning` | `Enigmoss API call failed` |
| `RequestSending` | not logged | |

The context holds `method`, `path`, `attempt`, `status`, `request_id`, `duration_ms`, `retry_in_s` and `error` (class and message), only for values that are set. Set the Laravel `ENIGMOSS_LOG_CHANNEL` or the Symfony `logger` option to enable it in the bridges.

## Per-call response metadata

`RequestOptions(onResponse: ...)` receives the `ResponseInfo` of the successful response:

```php
use Enigmoss\Http\ResponseInfo;
use Enigmoss\RequestOptions;

$payment = $enigmoss->payments->get($id, new RequestOptions(
    onResponse: static function (ResponseInfo $info): void {
        // $info->statusCode, ->requestId, ->idempotencyKey, ->replayed, ->rateLimitLimit,
        // ->rateLimitRemaining, ->attempts, ->location
    },
));
```

| `ResponseInfo` property | Meaning |
|---|---|
| `statusCode` | HTTP status. |
| `requestId` | `X-Request-Id`: what to quote to support. |
| `idempotencyKey` | The key the request was sent with, if any. |
| `replayed` | `true` when the server returned the stored response of an earlier request with the same key. |
| `rateLimitLimit`, `rateLimitRemaining` | From the `X-RateLimit-*` headers, when present. |
| `attempts` | Attempts made; `1` when the first succeeded. |
| `location` | The `Location` header, when present. |

`CursorPage::$info` carries the `ResponseInfo` of a list call, and `EnigmossClient::request()` returns a `RawResponse` with `info`, `data`, `meta` and the exact `body`.

For failures, `ApiException::$requestId` and `$idempotencyKey` hold the same identifiers.

## What to log

- Log `requestId`, the `order_id` and the exception class and `errorCode` of failures. Support can find a request by its id.
- Do not log request or response bodies: they contain addresses, amounts and customer data. Never log keys. See [Security](security.md).
- Alert on `RequestFailed` events with `outcomeUnknown()` for money endpoints, on repeated `RateLimitException`, and on `AuthenticationException` (a rotated key or a drifting clock).
