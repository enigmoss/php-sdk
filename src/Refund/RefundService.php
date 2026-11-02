<?php

declare(strict_types=1);

namespace Enigmoss\Refund;

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
 * Refunds. Requires the payout key; the server IP must be allowed for payout-key requests.
 * Creating refunds is limited to 1 request per minute per project by default.
 */
final class RefundService
{
    /**
     * @internal
     */
    public function __construct(private readonly Requester $requester) {}

    /**
     * Refunds a paid invoice, fully or partly. Returns the refund in status pending; follow it
     * with refund.* webhooks or get().
     *
     * A refund is paid out like a payout, so the attempt timeout is at least 180 seconds.
     * Money-safe to repeat: the Idempotency-Key is derived from payment ID and order_id. If an
     * error has outcomeUnknown() === true, repeat with the same order_id or call findByOrderId().
     * POST /api/v4/payments/{id}/refunds, permission payment_refund.
     *
     * @throws \Enigmoss\Exception\ConflictException     order_id used, or the payment cannot be refunded now.
     * @throws \Enigmoss\Exception\BusinessRuleException Amount too small/large, insufficient funds.
     * @throws \Enigmoss\Exception\RateLimitException
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function createForPayment(string $paymentId, CreatePaymentRefundRequest $request, ?RequestOptions $options = null): Refund
    {
        $result = $this->requester->execute(
            Endpoint::create('/payments/{id}/refunds', KeyScope::Payout, IdempotencyMode::Required, Endpoint::PAYOUT_TIMEOUT),
            ['id' => Assert::uuid($paymentId, 'paymentId')],
            body: $request->toBody(),
            options: $options,
            orderId: $request->orderId,
        );

        return Refund::fromPayload($result->payload());
    }

    /**
     * Refunds everything paid to a blocked static wallet in one payout (once per wallet).
     * POST /api/v4/wallets/{id}/refunds, permission payment_refund.
     *
     * @throws \Enigmoss\Exception\ConflictException The wallet is not blocked or was already refunded.
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function createForWallet(string $walletId, CreateWalletRefundRequest $request, ?RequestOptions $options = null): Refund
    {
        $result = $this->requester->execute(
            Endpoint::create('/wallets/{id}/refunds', KeyScope::Payout, IdempotencyMode::Required, Endpoint::PAYOUT_TIMEOUT),
            ['id' => Assert::uuid($walletId, 'walletId')],
            body: $request->toBody(),
            options: $options,
            orderId: $request->orderId,
        );

        return Refund::fromPayload($result->payload());
    }

    /**
     * GET /api/v4/refunds/{id}, permission payment_refund.
     *
     * @throws \Enigmoss\Exception\NotFoundException
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function get(string $id, ?RequestOptions $options = null): Refund
    {
        $result = $this->requester->execute(Endpoint::get('/refunds/{id}', KeyScope::Payout), ['id' => Assert::uuid($id, 'id')], options: $options);

        return Refund::fromPayload($result->payload());
    }

    /**
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function findByOrderId(string $orderId, ?RequestOptions $options = null): ?Refund
    {
        return $this->list(new ListRefundsQuery(orderId: $orderId, limit: 1), $options)->first();
    }

    /**
     * GET /api/v4/refunds, permission payment_refund.
     *
     * @return CursorPage<Refund>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function list(?ListRefundsQuery $query = null, ?RequestOptions $options = null): CursorPage
    {
        return $this->fetchPage($query ?? new ListRefundsQuery(), $options);
    }

    /**
     * @return \Generator<int, Refund>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function iterate(?ListRefundsQuery $query = null, ?RequestOptions $options = null): \Generator
    {
        $query ??= new ListRefundsQuery();

        return Paginator::iterate(fn(?string $cursor): CursorPage => $this->fetchPage($query->withCursor($cursor), $options, Paginator::ITERATE_MAX_RETRY_DELAY), $query->cursor);
    }

    /**
     * @return CursorPage<Refund>
     */
    private function fetchPage(ListRefundsQuery $query, ?RequestOptions $options, ?float $maxRetryDelay = null): CursorPage
    {
        $result = $this->requester->execute(Endpoint::get('/refunds', KeyScope::Payout), query: $query->toQuery(), options: $options, maxRetryDelay: $maxRetryDelay);

        return CursorPage::fromResult($result, static fn(Payload $p): Refund => Refund::fromPayload($p));
    }
}
