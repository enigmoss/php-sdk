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
- PSR-18 cannot tell whether a failed request was sent, so network errors are reported as `SendPhase::Unknown`. Money-moving requests are then retried only when an `Idempotency-Key` protects them (which is the default for create calls).

### SymfonyHttpClientTransport

Sends requests through Symfony HttpClient, so they show up in the profiler and can be mocked with `MockHttpClient`.

```php
use Enigmoss\Bridge\Symfony\Http\SymfonyHttpClientTransport;
use Enigmoss\EnigmossClient;
use Symfony\Component\HttpClient\HttpClient;

$enigmoss = new EnigmossClient($config, new SymfonyHttpClientTransport(HttpClient::create()));
```

Redirects are disabled and the request timeout is applied both as the idle timeout and as `max_duration`. Transport errors are reported as `SendPhase::Unknown`. With the Symfony bundle, set `transport: symfony` on a client to use the `http_client` service; see [Symfony](symfony.md).

### Writing your own

See [Custom HTTP client](custom-http-client.md).

## Clock and sleeper

For tests, `EnigmossClient` also accepts a `ClockInterface` and a `SleeperInterface` (retries sleep through it), and a list of `ObserverInterface`. See [Testing](testing.md) and [Logging and observability](logging-and-observability.md).

```php
new EnigmossClient($config, $transport, $clock, $sleeper, [$observer]);
```

## Reading the configuration back

```php
$config = $enigmoss->config();
echo $config->origin;        // https://api.example.com
echo $config->projectId;    // lower-case UUID
```
