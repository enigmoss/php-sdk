<?php

declare(strict_types=1);

namespace Enigmoss\Payment;

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
 * Invoices. Requires the payment key.
 */
final class PaymentService
{
    /**
     * @internal
     */
    public function __construct(private readonly Requester $requester) {}

    /**
     * Creates an invoice and returns it with the payment page URL.
     *
     * Safe to repeat: the default Idempotency-Key is derived from the order_id, so repeating the
     * same request returns the same invoice instead of a conflict (within 24 hours).
     * POST /api/v4/payments, permission payment_create_invoice.
     *
     * @throws \Enigmoss\Exception\ConflictException     The order_id is already used (existingResourceId() is the invoice).
     * @throws \Enigmoss\Exception\ValidationException
     * @throws \Enigmoss\Exception\BusinessRuleException Amount limits, unavailable currency or network.
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function create(CreatePaymentRequest $request, ?RequestOptions $options = null): Payment
    {
        $result = $this->requester->execute(
            Endpoint::create('/payments', KeyScope::Payment, IdempotencyMode::Supported),
            body: $request->toBody(),
            options: $options,
            orderId: $request->orderId,
        );

        return Payment::fromPayload($result->payload());
    }

    /**
     * GET /api/v4/payments/{id}, permission info_payments.
     *
     * @throws \Enigmoss\Exception\NotFoundException
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function get(string $id, ?RequestOptions $options = null): Payment
    {
        $result = $this->requester->execute(
            Endpoint::get('/payments/{id}', KeyScope::Payment),
            ['id' => Assert::uuid($id, 'id')],
            options: $options,
        );

        return Payment::fromPayload($result->payload());
    }

    /**
     * Finds the invoice of an order_id, or null. Use it to reconcile after an error whose
     * outcomeUnknown() is true. Static wallet deposits are not found by order_id: list by walletId.
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function findByOrderId(string $orderId, ?RequestOptions $options = null): ?Payment
    {
        return $this->list(new ListPaymentsQuery(orderId: $orderId, limit: 1), $options)->first();
    }

    /**
     * One page of invoices, newest first. GET /api/v4/payments, permission info_payments.
     *
     * @return CursorPage<Payment>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function list(?ListPaymentsQuery $query = null, ?RequestOptions $options = null): CursorPage
    {
        return $this->fetchPage($query ?? new ListPaymentsQuery(), $options);
    }

    /**
     * Every invoice matching the query, fetching pages lazily.
     *
     *     foreach ($enigmoss->payments->iterate(new ListPaymentsQuery(status: PaymentStatus::Paid)) as $payment) { ... }
     *
     * @return \Generator<int, Payment>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function iterate(?ListPaymentsQuery $query = null, ?RequestOptions $options = null): \Generator
    {
        $query ??= new ListPaymentsQuery();

        return Paginator::iterate(fn(?string $cursor): CursorPage => $this->fetchPage($query->withCursor($cursor), $options, Paginator::ITERATE_MAX_RETRY_DELAY), $query->cursor);
    }

    /**
     * Cancels an unpaid invoice (status pending or processing, nothing received yet).
     * POST /api/v4/payments/{id}/cancel, permission payment_cancel.
     *
     * @throws \Enigmoss\Exception\ConflictException The invoice can no longer be cancelled.
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function cancel(string $id, ?RequestOptions $options = null): Payment
    {
        $result = $this->requester->execute(
            Endpoint::action('/payments/{id}/cancel', KeyScope::Payment),
            ['id' => Assert::uuid($id, 'id')],
            options: $options,
        );

        return Payment::fromPayload($result->payload());
    }

    /**
     * Accepts an underpaid invoice as paid. For underpaid_waiting it asks the gateway to close the
     * invoice; the response may still be underpaid_waiting - wait for the payment.* webhook.
     * POST /api/v4/payments/{id}/mark-paid, permission payment_mark_paid.
     *
     * @throws \Enigmoss\Exception\ConflictException
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function markPaid(string $id, ?RequestOptions $options = null): Payment
    {
        $result = $this->requester->execute(
            Endpoint::action('/payments/{id}/mark-paid', KeyScope::Payment),
            ['id' => Assert::uuid($id, 'id')],
            options: $options,
        );

        return Payment::fromPayload($result->payload());
    }

    /**
     * Returns the AML verification link(s) of a locked payment, creating one when none is active.
     * POST /api/v4/payments/{id}/aml-verification-links, permission info_payments.
     *
     * @return list<AmlVerificationLink>
     *
     * @throws \Enigmoss\Exception\ConflictException The payment is not locked, or verification already started.
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function createAmlVerificationLinks(string $id, ?RequestOptions $options = null): array
    {
        $result = $this->requester->execute(
            Endpoint::action('/payments/{id}/aml-verification-links', KeyScope::Payment),
            ['id' => Assert::uuid($id, 'id')],
            options: $options,
        );

        return array_map(static fn(Payload $p): AmlVerificationLink => AmlVerificationLink::fromPayload($p), $result->payloads());
    }

    /**
     * @return CursorPage<Payment>
     */
    private function fetchPage(ListPaymentsQuery $query, ?RequestOptions $options, ?float $maxRetryDelay = null): CursorPage
    {
        $result = $this->requester->execute(
            Endpoint::get('/payments', KeyScope::Payment),
            query: $query->toQuery(),
            options: $options,
            maxRetryDelay: $maxRetryDelay,
        );

        return CursorPage::fromResult($result, static fn(Payload $p): Payment => Payment::fromPayload($p));
    }
}
