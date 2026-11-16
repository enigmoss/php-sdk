# Security

How to handle keys, requests and webhooks safely. To report a vulnerability in the SDK itself, see [SECURITY.md](../SECURITY.md).

## API keys

- **Wrap keys in `Secret`.** It never appears in `var_dump()`, `print_r()`, logs, stack traces or serialized payloads, and `serialize()` throws. `ClientConfig::__debugInfo()` masks keys too.
- **Keep keys out of source control.** Read them from environment variables or a secrets manager. Never commit `.env` files with real keys.
- **Use only the keys you need.** A service that only accepts payments needs only the payment key. Do not give web-facing code the payout key; run payouts, refunds and transfers from a separate worker or service that holds it.
- **Least privilege.** Enable only the permissions you use in the dashboard; `project->get()` shows what a key can do.
- **Rotate on suspicion of leakage.** Rotating the payment key also changes the webhook secret: during rotation verify webhooks with both secrets (`new WebhookVerifier($projectId, [new Secret($new), new Secret($old)])`).
- **Do not log request data.** The SDK's observers never log bodies, headers or keys; keep your own logging the same way.
- **Do not queue the client.** `EnigmossClient` and `Secret` refuse to be serialized so keys cannot end up in a queue payload or a cache entry. Resolve the client inside the job.

## Transport security

- The base URL must be `https` unless you set `allowInsecureHttp` (local development only). Never enable it in production.
- `CurlTransport` always verifies TLS peers and never follows redirects. If you write your own transport, do the same: see [Custom HTTP client](custom-http-client.md).
- A custom CA bundle or proxy is configured on `CurlTransport` (`caBundle`, `proxy`).
- The SDK rejects base URLs with credentials, a query string or an unexpected path.
- With `fallbackBaseUrl`, the API origin named by the fallback host is used only when the answer is signed with your project key for that very request (the request carries a one-time nonce, so a recorded answer cannot be replayed) and the origin is a plain public https host: no IP address, no `localhost`, no other port, no path. A compromised fallback host can therefore make the fallback fail, but cannot point your requests at a host of its choice. See [Configuration](configuration.md#api-origin-fallback).

## Payout IP whitelist

Requests signed with the payout key are accepted only from whitelisted IP addresses. Keep the whitelist short and route payout calls through a fixed egress IP. A request from an IP address the project does not allow gets `IpNotAllowedException` (`auth.ip_forbidden`).

## Request signing and clocks

Requests are signed with HMAC-SHA256 and are valid for a 300 second window. Keep your servers on NTP. The SDK re-signs once with a corrected clock when the server reports an expired timestamp.

## Webhooks

- **Verify every webhook** before acting on it. Never trust a request because it comes from a known IP or has the right path.
- **Verify the raw body.** Do not re-encode JSON before verifying.
- The verifier checks the project, a fresh timestamp (default 300 seconds, which limits replay) and the signature with a constant-time comparison.
- **Deduplicate by event id, atomically** (`claim()` / `release()`, see [Webhooks](webhooks.md#idempotent-handling)). A valid captured webhook can be replayed within the tolerance window, and the API itself redelivers events.
- **Act on state.** Before releasing goods, re-read the payment (`payments->get()`) if the decision is high-value, and check `isSuccessful()` and the amount. Do the re-read in your queue worker, not inside the webhook endpoint, which must answer within 15 seconds.
- Answer `400` for failed verification without leaking details, and `2xx` for everything valid, even if you ignore the event.
- Do not expose your webhook endpoint's responses beyond a short status: the API stores the first 64000 bytes of your response in the delivery attempt log.

## Idempotency and money

- Persist your `order_id` before calling a create endpoint, and never create a new `order_id` after `outcomeUnknown()`.
- Authorise on your side who may start a payout, refund or transfer; the SDK does not know your users. Validate amounts and addresses before calling.
- Amounts are decimal strings; never use floats for money.

## User-supplied data

- Treat `callbackUrl`, `returnUrl` and `successUrl` as configuration, not as user input, unless you validate the host.
- `metadata` and `description` are shown back in webhooks and, for `description`, on the payment page to the customer: do not put secrets in them.
- Escape any API value you render in HTML (descriptions, metadata, addresses).

## Dependencies

The SDK has no runtime dependencies. Run `composer audit` regularly for the rest of your application, and pin the SDK to a version range (`^1.0`). Read the [changelog](../CHANGELOG.md) when updating.
