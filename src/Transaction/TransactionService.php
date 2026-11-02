<?php

declare(strict_types=1);

namespace Enigmoss\Transaction;

use Enigmoss\Auth\KeyScope;
use Enigmoss\Common\CursorPage;
use Enigmoss\Internal\Endpoint;
use Enigmoss\Internal\Paginator;
use Enigmoss\Internal\Payload;
use Enigmoss\Internal\Requester;
use Enigmoss\RequestOptions;

final class TransactionService
{
    /**
     * @internal
     */
    public function __construct(private readonly Requester $requester) {}

    /**
     * Balance movements of the project, newest first.
     * GET /api/v4/transactions, payment key, permission info_transactions_history.
     *
     * @return CursorPage<Transaction>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function list(?ListTransactionsQuery $query = null, ?RequestOptions $options = null): CursorPage
    {
        return $this->fetchPage($query ?? new ListTransactionsQuery(), $options);
    }

    /**
     * @return \Generator<int, Transaction>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function iterate(?ListTransactionsQuery $query = null, ?RequestOptions $options = null): \Generator
    {
        $query ??= new ListTransactionsQuery();

        return Paginator::iterate(fn(?string $cursor): CursorPage => $this->fetchPage($query->withCursor($cursor), $options, Paginator::ITERATE_MAX_RETRY_DELAY), $query->cursor);
    }

    /**
     * @return CursorPage<Transaction>
     */
    private function fetchPage(ListTransactionsQuery $query, ?RequestOptions $options, ?float $maxRetryDelay = null): CursorPage
    {
        $result = $this->requester->execute(Endpoint::get('/transactions', KeyScope::Payment), query: $query->toQuery(), options: $options, maxRetryDelay: $maxRetryDelay);

        return CursorPage::fromResult($result, static fn(Payload $p): Transaction => Transaction::fromPayload($p));
    }
}
