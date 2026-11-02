<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Unit\Resources;

use Enigmoss\Common\PaymentMethodRef;
use Enigmoss\Event\DeliveryStatus;
use Enigmoss\Event\ListEventsQuery;
use Enigmoss\Event\PaymentEvent;
use Enigmoss\Exception\InvalidArgumentException;
use Enigmoss\Http\HttpRequest;
use Enigmoss\Http\ResponseInfo;
use Enigmoss\Payment\CreatePaymentRequest;
use Enigmoss\Payment\ListPaymentsQuery;
use Enigmoss\Payment\PaymentStatus;
use Enigmoss\Payment\Theme;
use Enigmoss\Payout\CreatePayoutRequest;
use Enigmoss\Payout\PayoutPriority;
use Enigmoss\Payout\PayoutStatus;
use Enigmoss\Payout\QuotePayoutRequest;
use Enigmoss\Refund\CreatePaymentRefundRequest;
use Enigmoss\Refund\RefundSource;
use Enigmoss\RequestOptions;
use Enigmoss\Testing\Fixtures;
use Enigmoss\Testing\MockTransport;
use Enigmoss\Tests\Support\ClientFactory;
use Enigmoss\Transaction\ListTransactionsQuery;
use Enigmoss\Transaction\TransactionType;
use Enigmoss\Transfer\CreateTransferRequest;
use Enigmoss\Wallet\CreateWalletRequest;
use Enigmoss\Wallet\WalletStatus;
use PHPUnit\Framework\TestCase;

final class ResourcesTest extends TestCase
{
    private const PAYMENT_ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    public function testCreatePaymentSendsTheWireFormat(): void
    {
        $transport = (new MockTransport())->queueData(Fixtures::payment(), 201);

        $payment = ClientFactory::make($transport)->payments->create(new CreatePaymentRequest(
            amount: '10.50',
            currencyCode: 'USDT',
            orderId: 'order-42',
            expiresIn: 600,
            allowedMethods: [new PaymentMethodRef('USDT', 'tron'), new PaymentMethodRef('BTC')],
            metadata: [],
            theme: Theme::Dark,
        ));

        $request = $this->request($transport);
        self::assertSame('POST', $request->method);
        self::assertSame('https://api.example.com/api/v4/payments', $request->url);
        self::assertSame(
            '{"amount":"10.50","currency_code":"USDT","order_id":"order-42","expires_in":600,"allowed_methods":[{"currency_code":"USDT","network_code":"tron"},{"currency_code":"BTC"}],"metadata":{},"theme":"dark"}',
            $request->body,
        );
        self::assertSame($this->signature(ClientFactory::PAYMENT_SECRET, 'POST', '/api/v4/payments', $request->body ?? ''), $request->headers['X-Signature']);
        self::assertStringStartsWith('sdk1-', $request->headers['Idempotency-Key']);
        self::assertSame(PaymentStatus::Pending, $payment->status);
        self::assertSame('10.50', $payment->amount);
        self::assertSame('2026-01-01T01:00:00+00:00', $payment->expiresAt?->format(DATE_ATOM));
    }

    public function testRepeatedCreateUsesTheSameIdempotencyKey(): void
    {
        $transport = (new MockTransport())->queueData(Fixtures::payment(), 201)->queueData(Fixtures::payment(), 201, headers: ['Idempotent-Replayed' => 'true']);
        $client = ClientFactory::make($transport);
        $request = new CreatePaymentRequest(amount: '10.50', currencyCode: 'USDT', orderId: 'order-42');

        $client->payments->create($request);
        $replayed = null;
        $client->payments->create($request, new RequestOptions(onResponse: static function (ResponseInfo $info) use (&$replayed): void {
            $replayed = $info->replayed;
        }));

        [$first, $second] = $transport->requests();
        self::assertSame($first->headers['Idempotency-Key'], $second->headers['Idempotency-Key']);
        self::assertTrue($replayed);
    }

    public function testFloatLikeAndListMetadataAreRejected(): void
    {
        try {
            new CreatePaymentRequest(amount: '1e3', currencyCode: 'USDT', orderId: 'o');
            self::fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        new CreatePaymentRequest(amount: '1', currencyCode: 'USDT', orderId: 'o', metadata: ['a', 'b']);
    }

    public function testPaidPaymentIsHydrated(): void
    {
        $transport = (new MockTransport())->queueData(Fixtures::paidPayment(['metadata' => ['cart' => 7]]));

        $payment = ClientFactory::make($transport)->payments->get(self::PAYMENT_ID);

        self::assertTrue($payment->status->isPaid());
        self::assertTrue($payment->isFinal);
        self::assertSame('10.29', $payment->settlement->amount);
        self::assertSame('tron', $payment->payer->networkCode);
        self::assertSame(['cart' => 7], $payment->metadata);
        self::assertSame('https://api.example.com/api/v4/payments/' . self::PAYMENT_ID, $this->request($transport)->url);
    }

    public function testUnknownStatusDoesNotBreakHydration(): void
    {
        $transport = (new MockTransport())->queueData(Fixtures::payment(['status' => 'brand_new_status']));

        self::assertSame(PaymentStatus::Unknown, ClientFactory::make($transport)->payments->get(self::PAYMENT_ID)->status);
    }

    public function testListUsesUtcDatesAndIterateWalksAllPages(): void
    {
        $transport = (new MockTransport())
            ->queueData([Fixtures::payment(['order_id' => 'a']), Fixtures::payment(['order_id' => 'b'])], meta: ['cursor' => ['next' => 'c2', 'prev' => null, 'per_page' => 2, 'has_more' => true]])
            ->queueData([Fixtures::payment(['order_id' => 'c'])], meta: ['cursor' => ['next' => null, 'prev' => 'c1', 'per_page' => 2, 'has_more' => false]]);

        $query = new ListPaymentsQuery(status: PaymentStatus::Paid, createdFrom: new \DateTimeImmutable('2026-10-01 03:00:00', new \DateTimeZone('+03:00')), limit: 2);
        $orderIds = [];
        foreach (ClientFactory::make($transport)->payments->iterate($query) as $payment) {
            $orderIds[] = $payment->orderId;
        }

        self::assertSame(['a', 'b', 'c'], $orderIds);
        [$first, $second] = $transport->requests();
        self::assertSame('https://api.example.com/api/v4/payments?status=paid&created_from=2026-10-01T00%3A00%3A00Z&limit=2', $first->url);
        self::assertStringEndsWith('&limit=2&cursor=c2', $second->url);
    }

    public function testFindByOrderIdReturnsNullWhenAbsent(): void
    {
        $transport = (new MockTransport())->queueData([], meta: ['cursor' => ['next' => null, 'prev' => null, 'per_page' => 1, 'has_more' => false]]);

        self::assertNull(ClientFactory::make($transport)->payouts->findByOrderId('withdrawal-42'));
        self::assertSame('https://api.example.com/api/v4/payouts?order_id=withdrawal-42&limit=1', $this->request($transport)->url);
    }

    public function testPayoutUsesThePayoutKeyAndRequiredIdempotency(): void
    {
        $transport = (new MockTransport())->queueData(Fixtures::payout(), 201);

        $payout = ClientFactory::make($transport)->payouts->create(new CreatePayoutRequest(
            orderId: 'withdrawal-42',
            amount: '100.00',
            currencyCode: 'USDT',
            address: 'TRecipientabcdefghijklmnopqrstuvwx',
            networkCode: 'tron',
            feeDeductedFromAmount: false,
            priority: PayoutPriority::High,
        ));

        $request = $this->request($transport);
        self::assertSame($this->signature(ClientFactory::PAYOUT_SECRET, 'POST', '/api/v4/payouts', $request->body ?? ''), $request->headers['X-Signature']);
        self::assertSame('{"order_id":"withdrawal-42","amount":"100.00","currency_code":"USDT","network_code":"tron","address":"TRecipientabcdefghijklmnopqrstuvwx","fee_deducted_from_amount":false,"priority":"high"}', $request->body);
        self::assertArrayHasKey('Idempotency-Key', $request->headers);
        self::assertSame(PayoutStatus::Pending, $payout->status);
        self::assertSame('1.00', $payout->charged->fee);
    }

    public function testQuoteIsNotIdempotent(): void
    {
        $transport = (new MockTransport())->queueData(Fixtures::payoutQuote());

        $quote = ClientFactory::make($transport)->payouts->quote(new QuotePayoutRequest('100.00', 'USDT', 'TRecipientabcdefghijklmnopqrstuvwx', 'tron'));

        self::assertArrayNotHasKey('Idempotency-Key', $this->request($transport)->headers);
        self::assertSame('99.00', $quote->recipient->amount);
    }

    public function testRefundKeyDependsOnThePayment(): void
    {
        $transport = (new MockTransport())->queueData(Fixtures::refund(), 201)->queueData(Fixtures::refund(), 201);
        $client = ClientFactory::make($transport);
        $request = new CreatePaymentRefundRequest('refund-42', 'TCustomerabcdefghijklmnopqrstuvwxy');

        $refund = $client->refunds->createForPayment(self::PAYMENT_ID, $request);
        $client->refunds->createForPayment('0f8fad5b-d9cb-469f-a165-70867728950e', $request);

        [$first, $second] = $transport->requests();
        self::assertNotSame($first->headers['Idempotency-Key'], $second->headers['Idempotency-Key']);
        self::assertSame(RefundSource::Payment, $refund->source);
    }

    public function testWalletLifecycle(): void
    {
        $transport = (new MockTransport())
            ->queueData(Fixtures::wallet(), 201)
            ->queueData(Fixtures::wallet(['status' => 'blocked', 'refund_incoming' => true]));
        $client = ClientFactory::make($transport);

        $wallet = $client->wallets->create(new CreateWalletRequest('USDT', 'tron', 'customer-7'));
        $blocked = $client->wallets->block($wallet->id, refundIncoming: true);

        self::assertSame('{"refund_incoming":true}', $transport->lastRequest()?->body);
        self::assertSame(WalletStatus::Blocked, $blocked->status);
        self::assertTrue($blocked->refundIncoming);
    }

    public function testCatalogBalancesTransactionsAndTransfers(): void
    {
        $transport = (new MockTransport())
            ->queueData([['currency_code' => 'USDT', 'network_code' => 'tron', 'is_enabled' => true, 'discount_percent' => 0]])
            ->queueData(['currency_code' => 'USDT', 'network_code' => 'tron', 'is_enabled' => true, 'discount_percent' => 5])
            ->queueData([['from' => 'BTC', 'to' => 'USDT', 'rate' => '89820.000000000000000000']])
            ->queueData([Fixtures::balance()])
            ->queueData([Fixtures::transaction(['type' => 'legacy_thing'])], meta: ['cursor' => ['next' => null, 'prev' => null, 'per_page' => 20, 'has_more' => false]])
            ->queueData(Fixtures::transfer(), 201)
            ->queueData([['currency_code' => 'USDT', 'network_code' => 'tron', 'contract_address' => 'TR7', 'min_amount' => '1', 'max_amount' => '100000', 'fee' => ['amount' => '1', 'percent' => '0.5']]])
            ->queueData(['currency_code' => 'USDT', 'network_code' => 'tron', 'network_name' => 'Tron', 'token_standard' => 'TRC-20', 'min_amount' => '1', 'max_amount' => '100000', 'decimals' => 6, 'is_memo_available' => false, 'priorities' => [['priority' => 'recommended', 'fee' => '1']]]);
        $client = ClientFactory::make($transport);

        self::assertCount(1, $client->paymentMethods->list());
        self::assertSame(5, $client->paymentMethods->updateDiscount('usdt', 'tron', 5)->discountPercent);
        self::assertSame('PATCH', $this->request($transport)->method);
        self::assertSame('89820.000000000000000000', $client->exchangeRates->list('BTC')[0]->rate);
        self::assertSame('490.50', $client->balances->list()[0]->balance);
        self::assertSame(TransactionType::Unknown, $client->transactions->list(new ListTransactionsQuery(type: TransactionType::Payment))->first()?->type);
        self::assertSame('25.00', $client->transfers->create(new CreateTransferRequest('transfer-1', '25.00', 'USDT'))->amount);
        self::assertSame('0.5', $client->payoutMethods->list()[0]->feePercent);
        self::assertSame(6, $client->payoutMethods->get('USDT', 'tron')->decimals);
        self::assertSame('https://api.example.com/api/v4/payout-methods/USDT/tron', $this->request($transport)->url);
    }

    public function testEventRecordsCarryTheSameEventTypeAsWebhooks(): void
    {
        $transport = (new MockTransport())->queueData([Fixtures::eventRecord()], meta: ['cursor' => ['next' => null, 'prev' => null, 'per_page' => 20, 'has_more' => false]]);

        $record = ClientFactory::make($transport)->events->list(new ListEventsQuery(deliveryStatus: DeliveryStatus::Failed))->first();

        self::assertNotNull($record);
        self::assertInstanceOf(PaymentEvent::class, $record->payload);
        self::assertSame(PaymentStatus::Paid, $record->payload->status());
        self::assertSame('10.50', $record->payload->payment()->paid->amount);
    }

    private function request(MockTransport $transport): HttpRequest
    {
        $request = $transport->lastRequest();
        self::assertNotNull($request);

        return $request;
    }

    private function signature(string $secret, string $method, string $uri, string $body): string
    {
        return hash_hmac('sha256', "1767225600\n" . ClientFactory::PROJECT . "\n{$method}\n{$uri}\n{$body}", $secret);
    }
}
