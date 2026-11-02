<?php

declare(strict_types=1);

namespace Enigmoss\Wallet;

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
 * Static wallets. Requires the payment key.
 */
final class WalletService
{
    /**
     * @internal
     */
    public function __construct(private readonly Requester $requester) {}

    /**
     * Creates a static wallet. Safe to repeat with the same order_id (the default Idempotency-Key
     * is derived from it). POST /api/v4/wallets, permission payment_create_static_wallet.
     *
     * @throws \Enigmoss\Exception\ConflictException   The order_id already has a wallet (existingResourceId()).
     * @throws \Enigmoss\Exception\DependencyException The gateway could not create the address; retry later.
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function create(CreateWalletRequest $request, ?RequestOptions $options = null): Wallet
    {
        $result = $this->requester->execute(
            Endpoint::create('/wallets', KeyScope::Payment, IdempotencyMode::Supported),
            body: $request->toBody(),
            options: $options,
            orderId: $request->orderId,
        );

        return Wallet::fromPayload($result->payload());
    }

    /**
     * GET /api/v4/wallets/{id}, permission info_payments.
     *
     * @throws \Enigmoss\Exception\NotFoundException
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function get(string $id, ?RequestOptions $options = null): Wallet
    {
        $result = $this->requester->execute(Endpoint::get('/wallets/{id}', KeyScope::Payment), ['id' => Assert::uuid($id, 'id')], options: $options);

        return Wallet::fromPayload($result->payload());
    }

    /**
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function findByOrderId(string $orderId, ?RequestOptions $options = null): ?Wallet
    {
        return $this->list(new ListWalletsQuery(orderId: $orderId, limit: 1), $options)->first();
    }

    /**
     * GET /api/v4/wallets, permission info_payments.
     *
     * @return CursorPage<Wallet>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function list(?ListWalletsQuery $query = null, ?RequestOptions $options = null): CursorPage
    {
        return $this->fetchPage($query ?? new ListWalletsQuery(), $options);
    }

    /**
     * @return \Generator<int, Wallet>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function iterate(?ListWalletsQuery $query = null, ?RequestOptions $options = null): \Generator
    {
        $query ??= new ListWalletsQuery();

        return Paginator::iterate(fn(?string $cursor): CursorPage => $this->fetchPage($query->withCursor($cursor), $options, Paginator::ITERATE_MAX_RETRY_DELAY), $query->cursor);
    }

    /**
     * Blocks a static wallet. $refundIncoming is fixed at the first block and cannot be undone;
     * repeating the call with the same value returns the wallet unchanged.
     * POST /api/v4/wallets/{id}/block, permission wallet_block.
     *
     * @throws \Enigmoss\Exception\ConflictException Deactivated wallet, or blocked with another refundIncoming.
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function block(string $id, bool $refundIncoming = false, ?RequestOptions $options = null): Wallet
    {
        $result = $this->requester->execute(
            Endpoint::action('/wallets/{id}/block', KeyScope::Payment),
            ['id' => Assert::uuid($id, 'id')],
            body: ['refund_incoming' => $refundIncoming],
            options: $options,
        );

        return Wallet::fromPayload($result->payload());
    }

    /**
     * @return CursorPage<Wallet>
     */
    private function fetchPage(ListWalletsQuery $query, ?RequestOptions $options, ?float $maxRetryDelay = null): CursorPage
    {
        $result = $this->requester->execute(Endpoint::get('/wallets', KeyScope::Payment), query: $query->toQuery(), options: $options, maxRetryDelay: $maxRetryDelay);

        return CursorPage::fromResult($result, static fn(Payload $p): Wallet => Wallet::fromPayload($p));
    }
}
