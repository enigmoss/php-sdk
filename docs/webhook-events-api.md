# Webhook events API

The events journal lets you inspect delivery attempts, redeliver events and **replay missed webhooks** through the same handler you use for live ones. **Payment key**, permission `webhooks`. Events are kept for **60 days**.

```php
$enigmoss->events;     // Enigmoss\Event\EventService
$enigmoss->webhooks;   // Enigmoss\Webhook\WebhookService (verification and test webhooks)
```

## EventRecord

| Property | Type | Notes |
|---|---|---|
| `id` | `string` | The event id; the same as `WebhookEvent::$id` of the live webhook. |
| `event` | `string` | e.g. `payment.paid`. |
| `objectType` | `?WebhookObjectType` | |
| `objectId` | `?string` | UUID of the payment, wallet, payout or refund. |
| `deliveryStatus` | `DeliveryStatus` | Status of the latest delivery attempt: `Pending`, `Delivered`, `Failed`, `Superseded` (a newer event of the same object made this one obsolete), `NoDestination` (no callback URL configured), `Unknown`. |
| `createdAt` | `DateTimeImmutable` | UTC. |
| `payload` | `WebhookEvent` | **The same typed event your endpoint receives.** |
| `attempts` | `list<DeliveryAttempt>` | Filled by `get()`, `redeliver()` and `sendTest()`; empty in lists. |

`DeliveryAttempt`: `number`, `trigger` (`AttemptTrigger`: `Auto`, `Retry`, `ProxyRetry`, `Manual`, `Bulk`, `Unknown`), `status` (`DeliveryStatus`), `url`, `responseStatusCode`, `responseBody` (the first 64000 bytes of your endpoint's response), `scheduledAt`, `createdAt`, `updatedAt`.

## List and inspect

```php
use Enigmoss\Event\DeliveryStatus;
use Enigmoss\Event\ListEventsQuery;
use Enigmoss\Event\WebhookObjectType;

$page = $enigmoss->events->list(new ListEventsQuery(
    deliveryStatus: DeliveryStatus::Failed,
    objectType: WebhookObjectType::Payment,
    createdFrom: new DateTimeImmutable('-2 days'),
    limit: 100,
));

$record = $enigmoss->events->get($page->first()->id);   // with all delivery attempts
foreach ($record->attempts as $attempt) {
    echo $attempt->number, ' ', $attempt->trigger->value, ' -> HTTP ', $attempt->responseStatusCode ?? '-', "\n";
}
```

Filters: `event` (like `payment.paid`), `objectType`, `objectId`, `deliveryStatus`, `createdFrom`, `createdTo`, `limit` (1-100), `cursor`. `iterate()` walks every page lazily; see [Pagination](pagination.md).

## Replay missed webhooks through your handler

`EventRecord::$payload` is the same `WebhookEvent` the live webhook produces, so one handler serves both:

```php
use Enigmoss\Event\DeliveryStatus;
use Enigmoss\Event\ListEventsQuery;
use Enigmoss\Event\WebhookEvent;

function handleEvent(WebhookEvent $event): void
{
    // your idempotent handler: deduplicate by $event->id, act on state
}

foreach ($enigmoss->events->iterate(new ListEventsQuery(
    deliveryStatus: DeliveryStatus::Failed,
    createdFrom: new DateTimeImmutable('-1 day'),
)) as $record) {
    handleEvent($record->payload);
}
```

This replays without asking the API to send anything again: you read the events yourself. Because the handler deduplicates by event id, running it over events you already processed is harmless. Replaying a `Superseded` event is not useful: a newer event of the same object exists, so fetch the object instead.

## Ask the API to redeliver

```php
$record = $enigmoss->events->redeliver($eventId);
```

Sends the event again, once, to the **current** callback URL. Only the latest event of an object can be redelivered, at most 10 times. Errors:

| Exception | When |
|---|---|
| `ConflictException` | Not the latest event of the object; `existingResourceId()` is the latest event. |
| `BusinessRuleException` | The redelivery limit was reached, or it is a `webhook.test` event. |
| `RateLimitException` | Too many requests. |

Redelivery calls are not retried automatically after an unknown outcome, because they have no idempotency key; look at the journal before repeating.

### Bulk redelivery

Queues the latest event of every matching object, once per minute, at most 1000 events.

```php
use Enigmoss\Event\BulkRedeliverRequest;
use Enigmoss\Event\DeliveryStatus;

$result = $enigmoss->events->redeliverBulk(new BulkRedeliverRequest(
    createdFrom: new DateTimeImmutable('-1 day'),   // required, not older than 60 days
    deliveryStatus: DeliveryStatus::Failed,
));

echo "queued {$result->queued}, already pending {$result->alreadyPending}, skipped outdated {$result->skippedOutdated}\n";
```

More than 1000 matching events gives a `BusinessRuleException`: narrow the filter (`event`, `objectType`, `objectId`, `deliveryStatus`, `createdTo`).

## Test webhooks

```php
$record = $enigmoss->webhooks->sendTest('https://shop.example/webhooks/enigmoss');   // or null for the project default URL

$attempt = $record->attempts[count($record->attempts) - 1] ?? null;
echo $record->deliveryStatus->value, ' ', $attempt?->responseStatusCode ?? '-', "\n";
```

Sends a `webhook.test` event once, without retries, and returns the event with the delivery attempt and your endpoint's response. Limit: 10 per minute. It carries no object: your handler receives a `TestEvent`.
