# Custom HTTP client

The default transport uses `ext-curl`. Two bundled bridges cover PSR-18 clients (`Psr18Transport`) and Symfony HttpClient (`SymfonyHttpClientTransport`); see [Configuration](configuration.md#transports). If neither fits, implement `Enigmoss\Http\TransportInterface` yourself:

```php
interface TransportInterface
{
    /** @throws TransportException */
    public function send(HttpRequest $request): HttpResponse;
}
```

A transport sends **one attempt**. Signing, retries, idempotency and error mapping are done by the SDK, not by the transport.

## The contract

1. **Send the URL string exactly as given.** No re-encoding, no normalisation, no added `?`, no trailing slash. The signature covers the exact path and query bytes.
2. **Send the headers and body exactly as given.** Do not compress the request, reformat the JSON or change the body. `$request->body` is `null` for requests without a body.
3. **Never follow redirects.** Return a 3xx response as is.
4. **Return any HTTP status as an `HttpResponse`**, 4xx and 5xx included. Do not throw on HTTP errors; the SDK maps them to typed exceptions.
5. **Decode transfer encodings** (gzip, chunked) so the body is the plain response text.
6. **Honour the timeouts.** `$request->timeout` is the total limit of the attempt and `$request->connectTimeout` the connect limit, in seconds.
7. **On a network failure throw `TransportException`** (or `ConnectionException`) and report how far the request got. See below.
8. **Verify TLS.** Never disable certificate verification.
9. **Do not retry inside the transport.** A hidden retry can send a money-moving request twice.

### HttpRequest and HttpResponse

```php
$request->method;          // 'GET', 'POST', 'PATCH'
$request->url;             // absolute URL, string
$request->headers;         // array<string, string>
$request->body;            // ?string
$request->timeout;         // float seconds
$request->connectTimeout;  // float seconds

// new HttpResponse(int $statusCode, array $headers, string $body)
// $headers: name => value or list of values, any case
```

## SendPhase rules

When no HTTP response arrives, tell the SDK how far the request got. Money-moving retries depend on it.

| Case | What to throw |
|---|---|
| The request **certainly did not leave the client** (DNS failure, connection refused, TLS handshake failure) | `ConnectionException`, or `new TransportException($message, SendPhase::NotSent)` |
| The request was sent, then the connection dropped or timed out | `new TransportException($message, SendPhase::Sent, timedOut: $timedOut)` |
| You cannot tell | `new TransportException($message, SendPhase::Unknown)` |

**When unsure, use `SendPhase::Unknown`.** It is treated exactly like `Sent`. Claiming `NotSent` for a request that was in fact sent can cause a money-moving request to be repeated in a way the server cannot deduplicate. Many HTTP clients fold several situations into one connection error class (a timeout may happen after the request was sent), which is why the bundled PSR-18 and Symfony transports report `Unknown`.

Effects:

| `SendPhase` | `outcomeUnknown()` | Retried |
|---|---|---|
| `NotSent` | `false` | Always. |
| `Sent` or `Unknown` | `true` | Reads: yes. Money creates: only when an `Idempotency-Key` protects them (the default for creates). Non-idempotent actions: no. |

Pass the underlying exception as `$previous` for debugging.

## Example: a Guzzle transport

```php
use Enigmoss\Exception\SendPhase;
use Enigmoss\Exception\TransportException;
use Enigmoss\Http\HttpRequest;
use Enigmoss\Http\HttpResponse;
use Enigmoss\Http\TransportInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class GuzzleTransport implements TransportInterface
{
    public function __construct(private readonly ClientInterface $http) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $options = [
            'headers' => $request->headers,
            'timeout' => $request->timeout,
            'connect_timeout' => $request->connectTimeout,
            'allow_redirects' => false,   // rule 3
            'http_errors' => false,       // rule 4
            'decode_content' => true,     // rule 5
        ];
        if ($request->body !== null) {
            $options['body'] = $request->body;
        }

        try {
            $response = $this->http->request($request->method, $request->url, $options);
        } catch (GuzzleException $e) {
            // Guzzle folds connect errors and timeouts together: report Unknown, not NotSent.
            throw new TransportException('HTTP request failed: ' . $e->getMessage(), SendPhase::Unknown, false, $e);
        }

        return new HttpResponse($response->getStatusCode(), $response->getHeaders(), (string) $response->getBody());
    }
}
```

Use it like any transport:

```php
$enigmoss = new EnigmossClient($config, new GuzzleTransport(new \GuzzleHttp\Client()));
```

## Test your transport

Run your transport against a local echo server and check that the URL, headers and body arrive byte for byte (including a query string with special characters and a non-ASCII JSON body), that a 4xx or 5xx is returned as a response, that a 3xx is not followed, and that a closed port throws a `TransportException`. Then run the SDK's calls through it with a test project.
