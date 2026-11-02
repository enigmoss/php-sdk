<?php

declare(strict_types=1);

namespace Enigmoss\Event;

use Enigmoss\Auth\KeyScope;
use Enigmoss\Common\CursorPage;
use Enigmoss\Internal\Assert;
use Enigmoss\Internal\Endpoint;
use Enigmoss\Internal\Paginator;
use Enigmoss\Internal\Payload;
use Enigmoss\Internal\Requester;
use Enigmoss\RequestOptions;

/**
 * The webhook events journal: list, inspect and redeliver events. Payment key, permission webhooks.
 */
final class EventService
{
    /**
     * @internal
     */
    public function __construct(private readonly Requester $requester) {}

    /**
     * GET /api/v4/events.
     *
     * @return CursorPage<EventRecord>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function list(?ListEventsQuery $query = null, ?RequestOptions $options = null): CursorPage
    {
        return $this->fetchPage($query ?? new ListEventsQuery(), $options);
    }

    /**
     * @return \Generator<int, EventRecord>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function iterate(?ListEventsQuery $query = null, ?RequestOptions $options = null): \Generator
    {
        $query ??= new ListEventsQuery();

        return Paginator::iterate(fn(?string $cursor): CursorPage => $this->fetchPage($query->withCursor($cursor), $options, Paginator::ITERATE_MAX_RETRY_DELAY), $query->cursor);
    }

    /**
     * The event with all delivery attempts. GET /api/v4/events/{id}.
     *
     * @throws \Enigmoss\Exception\NotFoundException
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function get(string $id, ?RequestOptions $options = null): EventRecord
    {
        $result = $this->requester->execute(Endpoint::get('/events/{id}', KeyScope::Payment), ['id' => Assert::uuid($id, 'id')], options: $options);

        return EventRecord::fromPayload($result->payload());
    }

    /**
     * Sends the event again, once, to the current callback URL. Only the latest event of an object
     * can be redelivered, at most 10 times. POST /api/v4/events/{id}/redeliver.
     *
     * @throws \Enigmoss\Exception\ConflictException     Not the latest event (existingResourceId() is the latest).
     * @throws \Enigmoss\Exception\BusinessRuleException Redelivery limit reached, or a webhook.test event.
     * @throws \Enigmoss\Exception\RateLimitException
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function redeliver(string $id, ?RequestOptions $options = null): EventRecord
    {
        $result = $this->requester->execute(Endpoint::nonIdempotent('/events/{id}/redeliver', KeyScope::Payment), ['id' => Assert::uuid($id, 'id')], options: $options);

        return EventRecord::fromPayload($result->payload());
    }

    /**
     * Queues the latest event of every matching object for redelivery. Once per minute.
     * POST /api/v4/events/redeliver.
     *
     * @throws \Enigmoss\Exception\BusinessRuleException More than 1000 events match; narrow the filter.
     * @throws \Enigmoss\Exception\RateLimitException
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function redeliverBulk(BulkRedeliverRequest $request, ?RequestOptions $options = null): BulkRedeliveryResult
    {
        $result = $this->requester->execute(Endpoint::nonIdempotent('/events/redeliver', KeyScope::Payment), body: $request->toBody(), options: $options);

        return BulkRedeliveryResult::fromPayload($result->payload());
    }

    /**
     * @return CursorPage<EventRecord>
     */
    private function fetchPage(ListEventsQuery $query, ?RequestOptions $options, ?float $maxRetryDelay = null): CursorPage
    {
        $result = $this->requester->execute(Endpoint::get('/events', KeyScope::Payment), query: $query->toQuery(), options: $options, maxRetryDelay: $maxRetryDelay);

        return CursorPage::fromResult($result, static fn(Payload $p): EventRecord => EventRecord::fromPayload($p));
    }
}
