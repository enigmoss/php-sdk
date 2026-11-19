# Symfony

Supported: Symfony 6.4, 7.4 and 8.x. The bundle is `Enigmoss\Bridge\Symfony\EnigmossBundle`.

## Install

```bash
composer require enigmoss/php-sdk
```

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Enigmoss\Bridge\Symfony\EnigmossBundle::class => ['all' => true],
];
```

## Configuration

`config/packages/enigmoss.yaml`, the complete tree with defaults:

```yaml
enigmoss:
    # Client that EnigmossClient, WebhookVerifier and the commands use.
    default_client: default

    # Service id of a PSR-3 logger, e.g. "logger". null disables API call logging.
    logger: null

    # How long a handled webhook event_id is remembered, seconds (3 days).
    webhook_dedupe_ttl: 259200

    # Cache pool service used by the webhook deduplicator.
    webhook_dedupe_cache: cache.app

    # Cache pool that keeps the API origin learned from the fallback host; null: memory only.
    origin_cache: cache.app

    # At least one client is required. The key is the client name.
    clients:
        default:
            base_url: '%env(ENIGMOSS_BASE_URL)%'            # required: your API host from the dashboard
            project_id: '%env(ENIGMOSS_PROJECT_ID)%'      # required: project UUID
            payment_key: '%env(ENIGMOSS_PAYMENT_KEY)%'      # default: null
            payout_key: '%env(default::ENIGMOSS_PAYOUT_KEY)%'   # default: null; "default::" yields null when unset
            timeout: 30.0
            connect_timeout: 10.0
            max_retries: 2                                  # 0 to 10
            max_retry_delay: 10.0
            webhook_tolerance: 300
            allow_insecure_http: false                      # local development only
            fallback_base_url: null                         # host asked for the API origin when base_url is unreachable
            transport: curl                                 # curl | symfony
```

`base_url` and `project_id` are required for every client; keys are optional but at least one is needed for the client to work. The environment variable names are your choice: the bundle reads the values from the YAML, not from fixed variables.

### Multiple clients

```yaml
enigmoss:
    default_client: shop1
    clients:
        shop1:
            base_url: '%env(ENIGMOSS_BASE_URL)%'
            project_id: '%env(SHOP1_PROJECT_ID)%'
            payment_key: '%env(SHOP1_PAYMENT_KEY)%'
            payout_key: '%env(SHOP1_PAYOUT_KEY)%'
        shop2:
            base_url: '%env(ENIGMOSS_BASE_URL)%'
            project_id: '%env(SHOP2_PROJECT_ID)%'
            payment_key: '%env(SHOP2_PAYMENT_KEY)%'
            transport: symfony
```

## Autowiring

The default client is autowired by type, whatever the argument is called:

```php
use Enigmoss\EnigmossClient;
use Enigmoss\Payment\CreatePaymentRequest;

final class CheckoutService
{
    public function __construct(private readonly EnigmossClient $enigmoss) {}

    public function start(string $orderId): ?string
    {
        return $this->enigmoss->payments->create(new CreatePaymentRequest('10.50', 'USDT', $orderId))->paymentUrl;
    }
}
```

Other clients are autowired by **argument name**: the name of the client plus `Client`. A client called `shop2` is injected into an `EnigmossClient $shop2Client` argument:

```php
final class Shop2Billing
{
    public function __construct(private readonly EnigmossClient $shop2Client) {}
}
```

The default client is also available as `$defaultClient` when its name is `default`.

**Names with an underscore.** Symfony normalises the argument name, so a client called `shop_us` autowires as either `$shopUsClient` or `$shop_usClient`. If you prefer not to depend on the naming, name the service explicitly:

```php
use Enigmoss\EnigmossClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ShopUsBilling
{
    public function __construct(
        #[Autowire(service: 'enigmoss.client.shop_us')] private readonly EnigmossClient $enigmoss,
    ) {}
}
```

The console commands select a client with `--client=shop_us` (see below).

Other services you can autowire or fetch:

| Service | Type or id |
|---|---|
| Default client | `Enigmoss\EnigmossClient` (public alias) |
| Client `<name>` | service `enigmoss.client.<name>`, argument `$<name>Client` |
| Default webhook verifier | `Enigmoss\Webhook\WebhookVerifier` |
| Verifier of client `<name>` | service `enigmoss.webhook_verifier.<name>` |
| Webhook deduplicator | `Enigmoss\Webhook\WebhookDeduplicatorInterface` (public alias of the cache-backed implementation) |

Like everywhere, the client is stateless and safe to share, but cannot be serialized: do not put it in a Messenger message.

## Webhooks: #[MapEnigmossWebhook]

Add the attribute to a controller argument of type `WebhookEvent`. The bundle verifies the signature of the raw body with the client's payment key and injects the typed event. An invalid webhook is answered with **HTTP 400** before your controller runs.

```php
namespace App\Controller;

use App\Webhook\EnigmossWebhookHandler;   // YOUR class: it queues or handles the event
use Enigmoss\Bridge\Symfony\Webhook\MapEnigmossWebhook;
use Enigmoss\Event\WebhookEvent;
use Enigmoss\Webhook\WebhookDeduplicatorInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;   // Symfony\Component\Routing\Annotation\Route on 6.4

final class EnigmossWebhookController
{
    #[Route('/webhooks/enigmoss', name: 'enigmoss_webhook', methods: ['POST'])]
    public function __invoke(
        #[MapEnigmossWebhook] WebhookEvent $event,
        WebhookDeduplicatorInterface $deduplicator,
        EnigmossWebhookHandler $handler,
    ): JsonResponse {
        $claimed = $event->id !== '';
        if ($claimed && !$deduplicator->claim($event->id)) {
            return new JsonResponse(['status' => 'duplicate']);
        }

        try {
            $handler->enqueue($event);   // keep this fast: the API waits 15 seconds
        } catch (\Throwable $e) {
            if ($claimed) {
                $deduplicator->release($event->id);   // let the next delivery try again
            }

            throw $e;   // a 500 makes the API deliver again
        }

        return new JsonResponse(['status' => 'accepted']);
    }
}
```

`EnigmossWebhookHandler` is **your** class (a service that puts the event on Messenger or handles it); the bundle does not ship it. In the handler, fulfil with `$event->payment()->isSuccessful()` (see [Webhooks](webhooks.md#events)). If your queue worker can fail for good, release the claim there too (`$deduplicator->release($eventId)`), otherwise a later redelivery is dropped as a duplicate.

For another client, name it: `#[MapEnigmossWebhook(client: 'shop2')] WebhookEvent $event`. An unknown client name throws a `LogicException`.

Match on the subclasses (`PaymentEvent`, `PayoutEvent`, `RefundEvent`, `TestEvent`, `UnknownEvent`) as described in [Webhooks](webhooks.md). The deduplicator stores event ids in `cache.app` (or `webhook_dedupe_cache`) for `webhook_dedupe_ttl` seconds.

**The bundled deduplicator is not atomic.** It is a PSR-6 cache, and PSR-6 has no atomic add: two deliveries of one event at the same instant can both claim it. If your handler is not idempotent, bind the database implementation to the interface instead:

```yaml
# config/services.yaml
services:
    Enigmoss\Webhook\PdoWebhookDeduplicator:
        arguments:
            $pdo: '@app.pdo'   # your PDO service; create the table first, see Webhooks

    Enigmoss\Webhook\WebhookDeduplicatorInterface: '@Enigmoss\Webhook\PdoWebhookDeduplicator'
```

`PdoWebhookDeduplicator` is atomic on PostgreSQL, MySQL/MariaDB and SQLite; the `CREATE TABLE` is in [Webhooks](webhooks.md#pdowebhookdeduplicator). The route must be reachable without authentication, and the firewall must not rewrite the body.

## API origin fallback

```yaml
enigmoss:
    origin_cache: cache.app
    clients:
        default:
            # ...
            fallback_base_url: '%env(default::ENIGMOSS_FALLBACK_BASE_URL)%'
```

With `fallback_base_url` (`https://tavrion.org` for the production API of Enigmoss, e.g. `ENIGMOSS_FALLBACK_BASE_URL=https://tavrion.org`) the client finds the new API host by itself when `base_url` cannot be reached: after the usual retries it asks the fallback host for the current API origin of the project, checks the signature of the answer and repeats the call there with the same `Idempotency-Key`. The rules are in [Configuration](configuration.md#api-origin-fallback).

The learned origin is kept in the `origin_cache` pool (`cache.app` by default) through `Enigmoss\Bridge\Symfony\Http\CacheOriginStore`, so PHP-FPM requests and workers share it; set `origin_cache: null` to keep it in the memory of the client only. The fallback needs the default **`transport: curl`**: with `transport: symfony` it never starts, because Symfony HttpClient does not report whether the connection was established.

## Commands

| Command | What it does |
|---|---|
| `php bin/console enigmoss:check [--client=<name>]` | Calls `project->get()` for each configured key of the client and prints the project, the permissions of the key, the API origin in use (and the fallback host, when one is configured) and a clock-drift warning above 30 s. Fails with exit code `1` if a key fails. |
| `php bin/console enigmoss:webhook-test [--url=...] [--client=<name>]` | Asks the API to send a `webhook.test` event to `--url` (or the project default URL) and prints the delivery status and your endpoint's HTTP status. |

Without `--client` both commands use the default client; `--client=shop2` selects another configured client.

## Transport: symfony

```yaml
enigmoss:
    clients:
        default:
            # ...
            transport: symfony
```

With `transport: symfony` the client sends requests through the `http_client` service, so API calls appear in the web profiler and can be mocked with `MockHttpClient`. This needs `symfony/http-client` and FrameworkBundle's `http_client` service. The default `curl` uses the SDK's built-in transport and needs `ext-curl`.

## Logging

Set `logger: logger` (any PSR-3 logger service id) to log API calls: method, path, status, request id, duration and retries. Bodies, headers and keys are never logged. See [Logging and observability](logging-and-observability.md).

## Testing

- Use `transport: symfony` for a client and give the test environment a `MockHttpClient` as `http_client`, so no request leaves the process.
- Or unit-test your services by building an `EnigmossClient` around `Enigmoss\Testing\MockTransport` and passing it to the service: see [Testing](testing.md).
- Test the webhook controller by sending the exact signed body. `SignedWebhook::serverVariables()` returns the headers as the `HTTP_X_PROJECT_UUID`, `HTTP_X_TIMESTAMP` and `HTTP_X_SIGNATURE` server variables:

```php
use Enigmoss\Secret;
use Enigmoss\Testing\Fixtures;
use Enigmoss\Testing\WebhookSimulator;

$webhook = WebhookSimulator::sign(Fixtures::webhook('payment.paid', Fixtures::paidPayment()), Fixtures::PROJECT_ID, new Secret('test-payment-secret'));

// With a KernelBrowser (WebTestCase):
$client->request('POST', '/webhooks/enigmoss', [], [], $webhook->serverVariables(), $webhook->body);
// 200 for a valid signature, 400 when signed with another secret

// Or with the kernel directly:
// $kernel->handle(Request::create('/webhooks/enigmoss', 'POST', [], [], [], $webhook->serverVariables(), $webhook->body));
```

The client of the test environment needs the same `project_id` and payment key you sign with.
