# Configuration

## ClientConfig

`Enigmoss\ClientConfig` is immutable. Only `baseUrl`, `projectId` and at least one key are required.

```php
use Enigmoss\AppInfo;
use Enigmoss\ClientConfig;
use Enigmoss\Secret;

$config = new ClientConfig(
    baseUrl: 'https://api.example.com',
    projectId: '3f2b8c1e-5d4a-4e7b-9c6f-1a2b3c4d5e6f',
    paymentKey: new Secret((string) getenv('ENIGMOSS_PAYMENT_KEY')),
    payoutKey: new Secret((string) getenv('ENIGMOSS_PAYOUT_KEY')),
    timeout: 30.0,
    connectTimeout: 10.0,
    maxRetries: 2,
    maxRetryDelay: 10.0,
    webhookTolerance: 300,
    allowInsecureHttp: false,
    appInfo: new AppInfo('my-shop-plugin', '2.4.0'),
    fallbackBaseUrl: 'https://tavrion.org',
    originStore: $originStore,
);
```

| Option | Type | Default | Meaning |
|---|---|---|---|
| `baseUrl` | `string` | required | Your API host from the dashboard, e.g. `https://api.example.com`. The SDK appends `/api/v4` itself; a base URL that already ends in `/api/v4` is accepted. Any other path is rejected, because the server signs the exact request path and a path-rewriting proxy would break every signature. Credentials, query strings and fragments in the URL are rejected. |
| `projectId` | `string` | required | Project UUID (case-insensitive; stored lower case). Sent as `X-Project-Uuid`. |
| `paymentKey` | `?Secret` | `null` | Payment API key. |
| `payoutKey` | `?Secret` | `null` | Payout API key. At least one of the two keys is required. |
| `timeout` | `float` | `30.0` | Total time limit of one HTTP attempt, in seconds. Must be positive. Payout and refund creation use at least 180 seconds (see [Idempotency and retries](idempotency-and-retries.md)). |
| `connectTimeout` | `float` | `10.0` | Time limit to establish a connection, in seconds. Must be positive. |
| `maxRetries` | `int` | `2` | Automatic retries after the first attempt, 0 to 10. `0` disables retries. |
| `maxRetryDelay` | `float` | `10.0` | The longest `Retry-After` the SDK will wait for; a longer one is thrown to you as `RateLimitException`. |
| `webhookTolerance` | `int` | `300` | Accepted age of a webhook `X-Timestamp`, in seconds. At least 1. |
| `allowInsecureHttp` | `bool` | `false` | Allow an `http://` base URL. Local development only. |
| `appInfo` | `?AppInfo` | `null` | Your application or plugin name and version, appended to the `User-Agent`. Useful if you ship a reusable integration; it helps support find your requests. |
| `fallbackBaseUrl` | `?string` | `null` | A second host that is asked for the current API origin of the project when `baseUrl` cannot be reached at all. Same rules as `baseUrl`. `null` or `''` turns the fallback off, and the SDK then behaves exactly as without the option. For the production API of Enigmoss it is `https://tavrion.org`. See [API origin fallback](#api-origin-fallback). |
| `originStore` | `?OriginStoreInterface` | `null` | Shares the origin learned from the fallback host between PHP processes. Without it the origin lives in the memory of the client. See [Origin store](#origin-store). |

Invalid configuration throws `Enigmoss\Exception\ConfigurationException` before anything is sent. Using an endpoint whose key you did not configure throws the same exception, for example calling `payouts->create()` without a `payoutKey`.

`var_dump($config)` prints the keys as `********`.

## Secret

`Enigmoss\Secret` holds an API key inside a closure. `var_dump()`, `print_r()`, `var_export()` and casting to string reveal nothing, and `serialize()` throws `LogicException` so a key cannot leak into a queue payload or a cache entry.

```php
$key = new Secret($_ENV['ENIGMOSS_PAYMENT_KEY']);
echo $key;   // ********
```

Load keys from environment variables or a secrets manager, not from source control. See [Security](security.md).

## Multiple projects

Platforms that serve several projects derive one client per project. `withCredentials()` returns a new client for another project that **shares the transport and observers** of the original:

```php
use Enigmoss\Secret;

$shop2 = $enigmoss->withCredentials(
    projectId: 'd6a7f0c2-6b1e-4b9a-9f3e-2c4d5e6f7a8b',
    paymentKey: new Secret($shop2PaymentKey),
    payoutKey: null,
);
```

All other settings (timeouts, retries, tolerance, `appInfo`) are carried over. The credentials are replaced entirely: keys you do not pass are not inherited. `ClientConfig::withCredentials()` does the same for a config object.

In Laravel use [connections](laravel.md#multiple-connections); in Symfony use [named clients](symfony.md#multiple-clients).

## Per-call overrides

Every service method accepts an optional last `RequestOptions` argument:

```php
use Enigmoss\Payout\CreatePayoutRequest;
use Enigmoss\RequestOptions;

$payout = $enigmoss->payouts->create(
    new CreatePayoutRequest(orderId: 'withdrawal-42', amount: '100.00', currencyCode: 'USDT', address: 'TRecipientAddress...', networkCode: 'tron'),
    new RequestOptions(timeout: 60.0, maxRetries: 1),
);
```

| Field | Meaning |
|---|---|
| `idempotencyKey` | Overrides the key the SDK would use. 1-64 printable ASCII characters, no spaces. |
| `timeout` | Total time limit of one attempt, seconds. |
| `maxRetries` | Automatic retries for this call (0 to 10). |
| `maxRetryDelay` | Longest `Retry-After` to wait for in this call. |
| `headers` | Extra headers, e.g. tracing. Protocol headers (`Accept`, `Content-Type`, `Host`, `User-Agent`, `Idempotency-Key`, `X-Project-Uuid`, `X-Timestamp`, `X-Signature`, ...) cannot be overridden, nor can the client-address headers the API gateway sets itself (`X-Real-IP`, `X-Forwarded-*`, `Forwarded`): the IP whitelist relies on them. |
| `extraBody` | Body fields the SDK does not model yet. May not override known fields. |
| `extraQuery` | Query parameters the SDK does not model yet. May not override known ones. |
| `onResponse` | A `Closure(ResponseInfo): void` that receives the metadata of the successful response. See [Logging and observability](logging-and-observability.md). |

## Transports

A transport sends one HTTP attempt. Signing, retries and error mapping are done by the SDK. Pass a transport as the second argument of `EnigmossClient`.

### CurlTransport (default)

Built on `ext-curl`. Used automatically when you pass no transport.

```php
use Enigmoss\EnigmossClient;
use Enigmoss\Http\CurlTransport;

$enigmoss = new EnigmossClient(
    $config,
    new CurlTransport(caBundle: '/etc/ssl/certs/corp-ca.pem', proxy: 'http://proxy.local:3128'),
);
```

| Option | Meaning |
|---|---|
| `caBundle` | Path to a CA bundle. The system store is used when `null`. |
| `proxy` | Outgoing proxy URL. |

Behaviour you can rely on: a fresh curl handle per request (safe under coroutine runtimes that hook curl), connection and DNS reuse through a curl share handle that is recreated after a fork, redirects are never followed, TLS peers are always verified. Without `ext-curl`, constructing it throws `ConfigurationException` with a pointer to the PSR-18 transport.

### Psr18Transport

Uses any PSR-18 client (Guzzle, Symfony `Psr18Client`, Buzz, ...). Requires `psr/http-client`, `psr/http-message` and PSR-17 factories, which you install yourself; the SDK itself has no dependencies.

```php
use Enigmoss\Bridge\Psr18\Psr18Transport;
use Enigmoss\EnigmossClient;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

$guzzle = new Client(['allow_redirects' => false, 'timeout' => 30, 'connect_timeout' => 10]);
$factory = new HttpFactory();

$enigmoss = new EnigmossClient($config, new Psr18Transport($guzzle, $factory, $factory));
```

You configure the client yourself:

- **Disable redirects** (`allow_redirects => false` for Guzzle).
- **Set timeouts** on the client. PSR-18 has no per-request options, so `ClientConfig::$timeout` and `RequestOptions::$timeout` are not applied. Allow at least 180 seconds if you create payouts or refunds.
- PSR-18 cannot tell whether a failed request was sent, so every failure is reported as `SendPhase::Unknown`. Money-moving requests are then retried only when an `Idempotency-Key` protects them (which is the default for create calls).
- For the same reason the [API origin fallback](#api-origin-fallback) never starts with this transport.

### SymfonyHttpClientTransport

Sends requests through Symfony HttpClient, so they show up in the profiler and can be mocked with `MockHttpClient`.

```php
use Enigmoss\Bridge\Symfony\Http\SymfonyHttpClientTransport;
use Enigmoss\EnigmossClient;
use Symfony\Component\HttpClient\HttpClient;

$enigmoss = new EnigmossClient($config, new SymfonyHttpClientTransport(HttpClient::create()));
```

Redirects are disabled and the request timeout is applied both as the idle timeout and as `max_duration`. Transport errors are reported as `SendPhase::Unknown`, so the [API origin fallback](#api-origin-fallback) never starts with this transport. With the Symfony bundle, set `transport: symfony` on a client to use the `http_client` service; see [Symfony](symfony.md).

### Writing your own

See [Custom HTTP client](custom-http-client.md).

## API origin fallback

The API host of a project can change: a domain may stop resolving or be blocked on the way to your server. With `fallbackBaseUrl` set, the client finds the new host by itself.

```php
$config = new ClientConfig(
    baseUrl: 'https://api.example.com',
    projectId: '3f2b8c1e-5d4a-4e7b-9c6f-1a2b3c4d5e6f',
    paymentKey: new Secret((string) getenv('ENIGMOSS_PAYMENT_KEY')),
    fallbackBaseUrl: 'https://tavrion.org',
);
```

The fallback host of the Enigmoss production API is **`https://tavrion.org`**. The option has no default: without it the SDK behaves exactly as before, so set it yourself. The fallback host answers only this lookup; it cannot serve as `baseUrl`.

The order is always **retries first, fallback second**:

1. The call is sent to the current origin and retried by the [usual rules](idempotency-and-retries.md#what-the-sdk-retries).
2. The fallback starts only when the retries are over **and** the last attempt proves that the API host could not be reached at all: a DNS failure, a refused connection, a failed TLS handshake or a connect timeout. A response timeout, a connection lost after the request was sent, a proxy failure and any HTTP response never start it; they are thrown as before.
3. The client sends one signed `GET /api/v4/project/platform-brand` to the fallback host, with a 3 second limit and without retries. The request carries a one-time `nonce` in its signed query. The answer is trusted only when it is signed with the key of the request (`X-Response-Signature`, `X-Response-Timestamp` within 300 seconds) and names a plain public https origin: host name, port 443 or none, nothing else. An IP address, `localhost`, a `.local` name, another port, a path or credentials are rejected. (`http://` is accepted for loopback hosts only, and only with `allowInsecureHttp`.)
4. The original request then starts over on the new origin with **the same body and the same `Idempotency-Key`**, a fresh timestamp and a fresh signature. The attempts are counted from one again; the total time limit of the call is not extended.
5. The new origin is used for the next calls for the `refresh_after` seconds of the answer (at least one hour, at most seven days). After that the configured `baseUrl` is tried again.

What you get when the fallback does not help:

| The fallback host | The call throws | Remembered |
|---|---|---|
| Answers with an error (401, 403, 404, 429, 5xx), without the API origin, with an origin that fails the check, or with a wrong or missing signature | That error, e.g. `NotFoundException` or `UnexpectedResponseException`. Its message starts with `API origin fallback failed:` and `getPrevious()` is the network error of the original request. `outcomeUnknown()` is the one of the original request: it was not sent, so nothing was created. | Nothing. |
| Names the origin that has just failed | The network error of the original request. | Nothing. |
| Cannot be reached either | The network error of the original request. | Nothing. |

After a fallback that gave no new origin the fallback host is left alone for 5 minutes; calls in that time fail with their own network error. Only the time is kept, not the answer. There is one fallback per call, and it needs 3 seconds of the call's time budget (`timeout * (maxRetries + 1) + maxRetryDelay * maxRetries`); when less is left it is skipped.

`project->platformBrand()` is the lookup itself: when the configured host cannot be reached, the signed answer of the fallback host is returned as the result.

A duplicate of a money-moving request is not possible: the fallback starts only when the request provably did not leave the client, and the repeated request carries the same `Idempotency-Key`.

### Which transports can start it

Only a transport that can tell "the connection was never established" from every other failure:

| Transport | Fallback |
|---|---|
| `CurlTransport` (default) | Yes. Behind an outgoing proxy (the `proxy` option or the `https_proxy`, `http_proxy`, `all_proxy` environment variables) only a failed DNS lookup of the API host counts: a refused connection or a timeout may be the proxy's own. |
| `Psr18Transport` | **Never.** PSR-18 does not say how far a failed request got. |
| `SymfonyHttpClientTransport` | **Never**, for the same reason. In the Symfony bundle keep the default `transport: curl` for clients with `fallback_base_url`. |
| Your own transport | When it throws `new ConnectionException($message, $previous, hostUnreachable: true)`; see [Custom HTTP client](custom-http-client.md#the-api-origin-fallback). |

### Origin store

A client keeps the learned origin in memory. That is enough for a long-running worker (Octane, RoadRunner, a queue worker). Under PHP-FPM a client lives for one HTTP request, so without a store every request would repeat the failed attempts and the lookup. Pass an `Enigmoss\Http\OriginStoreInterface` over a cache your processes share:

```php
use Enigmoss\Bridge\Psr16\Psr16OriginStore;

$config = new ClientConfig(
    // ...
    fallbackBaseUrl: 'https://tavrion.org',
    originStore: new Psr16OriginStore($cache),   // any PSR-16 cache: APCu, Redis, Memcached
);
```

| Adapter | Cache |
|---|---|
| `Enigmoss\Bridge\Psr16\Psr16OriginStore` | Any PSR-16 `CacheInterface`. |
| `Enigmoss\Bridge\Laravel\CacheOriginStore` | The Laravel cache. Wired automatically for connections with `fallback_base_url`; see [Laravel](laravel.md#api-origin-fallback). |
| `Enigmoss\Bridge\Symfony\Http\CacheOriginStore` | A PSR-6 pool, `cache.app` by default. Wired automatically for clients with `fallback_base_url`; see [Symfony](symfony.md#api-origin-fallback). |

The interface has two methods, `get(string $key): ?string` and `set(string $key, string $value, int $ttl): void`. The SDK stores one short JSON string per project and base URL (the origin with its expiry, and the time of the 5 minute pause), never a key or a response. The record carries an HMAC made with the project key, so something that can write to your cache but does not know the key cannot point the client at another host; a record with a wrong or missing HMAC is ignored. An origin read from the store also passes the same check as one received from the fallback host. A store that throws never breaks a call. Changing `baseUrl`, `fallbackBaseUrl` or the project starts from a clean entry.

### Seeing it happen

Observers receive `ClientEventType::OriginChanged` with `ClientEvent::$origin` and `$previousOrigin` when the client moves; `LoggerObserver` logs it as a warning. `$enigmoss->config()->origin` is the origin in use right now, and `enigmoss:check` prints it.

## Clock and sleeper

For tests, `EnigmossClient` also accepts a `ClockInterface` and a `SleeperInterface` (retries sleep through it), and a list of `ObserverInterface`. See [Testing](testing.md) and [Logging and observability](logging-and-observability.md).

```php
new EnigmossClient($config, $transport, $clock, $sleeper, [$observer]);
```

## Reading the configuration back

```php
$config = $enigmoss->config();
echo $config->origin;          // https://api.example.com - the API origin in use right now
echo $config->projectId;       // lower-case UUID
echo $config->fallbackOrigin;  // https://tavrion.org, or null
```

`origin` is the configured base URL until the [fallback](#api-origin-fallback) moves the client; then it is the origin the fallback host named.
