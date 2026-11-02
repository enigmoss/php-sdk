<?php

declare(strict_types=1);

namespace Enigmoss\Transfer;

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
 * Transfers to the personal account. Requires the payout key and permission transfer_to_personal.
 */
final class TransferService
{
    /**
     * @internal
     */
    public function __construct(private readonly Requester $requester) {}

    /**
     * Moves money from the business balance to the personal account, without a fee.
     * Money-safe to repeat with the same order_id. POST /api/v4/transfers.
     *
     * @throws \Enigmoss\Exception\ConflictException     The order_id is used.
     * @throws \Enigmoss\Exception\BusinessRuleException Insufficient funds, amount limits, no personal account.
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function create(CreateTransferRequest $request, ?RequestOptions $options = null): Transfer
    {
        $result = $this->requester->execute(
            Endpoint::create('/transfers', KeyScope::Payout, IdempotencyMode::Required),
            body: $request->toBody(),
            options: $options,
            orderId: $request->orderId,
        );

        return Transfer::fromPayload($result->payload());
    }

    /**
     * @throws \Enigmoss\Exception\NotFoundException
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function get(string $id, ?RequestOptions $options = null): Transfer
    {
        $result = $this->requester->execute(Endpoint::get('/transfers/{id}', KeyScope::Payout), ['id' => Assert::uuid($id, 'id')], options: $options);

        return Transfer::fromPayload($result->payload());
    }

    /**
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function findByOrderId(string $orderId, ?RequestOptions $options = null): ?Transfer
    {
        return $this->list(new ListTransfersQuery(orderId: $orderId, limit: 1), $options)->first();
    }

    /**
     * @return CursorPage<Transfer>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function list(?ListTransfersQuery $query = null, ?RequestOptions $options = null): CursorPage
    {
        return $this->fetchPage($query ?? new ListTransfersQuery(), $options);
    }

    /**
     * @return \Generator<int, Transfer>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function iterate(?ListTransfersQuery $query = null, ?RequestOptions $options = null): \Generator
    {
        $query ??= new ListTransfersQuery();

        return Paginator::iterate(fn(?string $cursor): CursorPage => $this->fetchPage($query->withCursor($cursor), $options, Paginator::ITERATE_MAX_RETRY_DELAY), $query->cursor);
    }

    /**
     * @return CursorPage<Transfer>
     */
    private function fetchPage(ListTransfersQuery $query, ?RequestOptions $options, ?float $maxRetryDelay = null): CursorPage
    {
        $result = $this->requester->execute(Endpoint::get('/transfers', KeyScope::Payout), query: $query->toQuery(), options: $options, maxRetryDelay: $maxRetryDelay);

        return CursorPage::fromResult($result, static fn(Payload $p): Transfer => Transfer::fromPayload($p));
    }
}
