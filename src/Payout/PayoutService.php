<?php

declare(strict_types=1);

namespace Enigmoss\Payout;

use Enigmoss\Auth\KeyScope;
use Enigmoss\Common\CursorPage;
use Enigmoss\Internal\Assert;
use Enigmoss\Internal\Endpoint;
use Enigmoss\Internal\IdempotencyMode;
use Enigmoss\Internal\Paginator;
use Enigmoss\Internal\Payload;
use Enigmoss\Internal\Requester;
use Enigmoss\RequestOptions;

/**
 * Payouts. Requires the payout key; the server IP must be allowed for payout-key requests.
 */
final class PayoutService
{
    /**
     * @internal
     */
    public function __construct(private readonly Requester $requester) {}

    /**
     * Creates a payout; the amount with fee is debited immediately.
     *
     * Money-safe to repeat: the Idempotency-Key is derived from the order_id, so a repeated call
     * (a retried job, a double click) returns the same payout and never pays twice. If an error
     * has outcomeUnknown() === true, repeat with the SAME order_id or call findByOrderId() - never
     * create a new order_id for the same withdrawal.
     * Creation may take up to ~160 seconds (the gateway is called synchronously), so the attempt
     * timeout of this call is at least 180 seconds unless RequestOptions::$timeout says otherwise.
     * POST /api/v4/payouts, permission payout_create, 10 requests/minute by default.
     *
     * @throws \Enigmoss\Exception\ConflictException         The order_id is used (existingResourceId() is the payout).
     * @throws \Enigmoss\Exception\BusinessRuleException     Insufficient funds, amount limits, unknown network.
     * @throws \Enigmoss\Exception\PermissionDeniedException Payouts disabled or IP not allowed.
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function create(CreatePayoutRequest $request, ?RequestOptions $options = null): Payout
    {
        $result = $this->requester->execute(
            Endpoint::create('/payouts', KeyScope::Payout, IdempotencyMode::Required, Endpoint::PAYOUT_TIMEOUT),
            body: $request->toBody(),
            options: $options,
            orderId: $request->orderId,
        );

        return Payout::fromPayload($result->payload());
    }

    /**
     * Calculates fee and amounts of a payout without creating it.
     * POST /api/v4/payouts/quote, permission payout_create.
     *
     * @throws \Enigmoss\Exception\BusinessRuleException
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function quote(QuotePayoutRequest $request, ?RequestOptions $options = null): PayoutQuote
    {
        $result = $this->requester->execute(Endpoint::calculation('/payouts/quote', KeyScope::Payout), body: $request->toBody(), options: $options);

        return PayoutQuote::fromPayload($result->payload());
    }

    /**
     * GET /api/v4/payouts/{id}, permission info_payout.
     *
     * @throws \Enigmoss\Exception\NotFoundException
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function get(string $id, ?RequestOptions $options = null): Payout
    {
        $result = $this->requester->execute(Endpoint::get('/payouts/{id}', KeyScope::Payout), ['id' => Assert::uuid($id, 'id')], options: $options);

        return Payout::fromPayload($result->payload());
    }

    /**
     * Finds the payout of an order_id, or null: the reconciliation step after an unknown outcome.
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function findByOrderId(string $orderId, ?RequestOptions $options = null): ?Payout
    {
        return $this->list(new ListPayoutsQuery(orderId: $orderId, limit: 1), $options)->first();
    }

    /**
     * GET /api/v4/payouts, permission info_payouts_list.
     *
     * @return CursorPage<Payout>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function list(?ListPayoutsQuery $query = null, ?RequestOptions $options = null): CursorPage
    {
        return $this->fetchPage($query ?? new ListPayoutsQuery(), $options);
    }

    /**
     * @return \Generator<int, Payout>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function iterate(?ListPayoutsQuery $query = null, ?RequestOptions $options = null): \Generator
    {
        $query ??= new ListPayoutsQuery();

        return Paginator::iterate(fn(?string $cursor): CursorPage => $this->fetchPage($query->withCursor($cursor), $options, Paginator::ITERATE_MAX_RETRY_DELAY), $query->cursor);
    }

    /**
     * @return CursorPage<Payout>
     */
    private function fetchPage(ListPayoutsQuery $query, ?RequestOptions $options, ?float $maxRetryDelay = null): CursorPage
    {
        $result = $this->requester->execute(Endpoint::get('/payouts', KeyScope::Payout), query: $query->toQuery(), options: $options, maxRetryDelay: $maxRetryDelay);

        return CursorPage::fromResult($result, static fn(Payload $p): Payout => Payout::fromPayload($p));
    }
}
