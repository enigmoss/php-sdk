<?php

declare(strict_types=1);

namespace Enigmoss\Tests\E2E;

use Enigmoss\ClientConfig;
use Enigmoss\EnigmossClient;
use Enigmoss\Exception\ConflictException;
use Enigmoss\Exception\IdempotencyException;
use Enigmoss\Exception\IpNotAllowedException;
use Enigmoss\Http\ResponseInfo;
use Enigmoss\Payment\CreatePaymentRequest;
use Enigmoss\Payment\ListPaymentsQuery;
use Enigmoss\Payment\PaymentStatus;
use Enigmoss\Payout\QuotePayoutRequest;
use Enigmoss\RequestOptions;
use Enigmoss\Secret;
use Enigmoss\Wallet\CreateWalletRequest;
use Enigmoss\Wallet\WalletStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a real API environment. Opt-in:
 *
 *   ENIGMOSS_E2E_BASE_URL=http://localhost:8083 ENIGMOSS_E2E_PROJECT_ID=... \
 *   ENIGMOSS_E2E_PAYMENT_KEY=... [ENIGMOSS_E2E_PAYOUT_KEY=...] composer test:e2e
 *
 * Creates real invoices and static wallets: use a test project only.
 */
#[Group('e2e')]
final class LiveApiTest extends TestCase
{
    private static ?EnigmossClient $client = null;

    public function testCredentialsAndCatalog(): void
    {
        $client = $this->client();

        $project = $client->project->get();
        self::assertSame($client->config()->projectId, $project->id);
        self::assertLessThan(300, abs($project->serverTime - time()));

        self::assertNotEmpty($client->paymentMethods->list());
        $client->exchangeRates->list();
        $client->payoutMethods->list();
        $client->balances->list();
        $client->transactions->list();
        $client->events->list();
        $this->addToAssertionCount(1);
    }

    public function testInvoiceLifecycleIsIdempotent(): void
    {
        $client = $this->client();
        $orderId = 'sdk-e2e-' . bin2hex(random_bytes(6));
        $request = new CreatePaymentRequest(amount: '15.00', currencyCode: 'USDT', orderId: $orderId, metadata: ['source' => 'sdk-e2e']);

        $payment = $client->payments->create($request);
        $info = null;
        $again = $client->payments->create($request, new RequestOptions(onResponse: static function (ResponseInfo $i) use (&$info): void {
            $info = $i;
        }));

        self::assertSame($payment->id, $again->id);
        self::assertInstanceOf(ResponseInfo::class, $info);
        self::assertTrue($info->replayed, 'A repeated create must be a replay of the stored response.');
        self::assertSame(['source' => 'sdk-e2e'], $payment->metadata);
        self::assertSame($payment->id, $client->payments->get($payment->id)->id);
        self::assertSame($payment->id, $client->payments->findByOrderId($orderId)?->id);

        try {
            $client->payments->create(new CreatePaymentRequest('16.00', 'USDT', $orderId));
            self::fail('A different body with the same order_id must not create a second invoice.');
        } catch (IdempotencyException|ConflictException) {
            $this->addToAssertionCount(1);
        }

        try {
            $client->payments->create(new CreatePaymentRequest('16.00', 'USDT', $orderId), new RequestOptions(idempotencyKey: 'e2e-' . bin2hex(random_bytes(8))));
            self::fail('Expected 409 resource.conflict.');
        } catch (ConflictException $e) {
            self::assertSame($payment->id, $e->existingResourceId());
        }

        $cancelled = $client->payments->cancel($payment->id);
        self::assertContains($cancelled->status, [PaymentStatus::Cancelled, PaymentStatus::Expired]);

        $page = $client->payments->list(new ListPaymentsQuery(limit: 2));
        self::assertLessThanOrEqual(2, \count($page));
    }

    public function testStaticWallet(): void
    {
        $client = $this->client();
        $orderId = 'sdk-e2e-wallet-' . bin2hex(random_bytes(6));

        try {
            $wallet = $client->wallets->create(new CreateWalletRequest('USDT', 'tron', $orderId));
        } catch (\Enigmoss\Exception\DependencyException $e) {
            self::markTestSkipped('The gateway of this environment cannot create addresses: ' . $e->getMessage());
        }

        self::assertSame(WalletStatus::Active, $wallet->status);
        self::assertSame(WalletStatus::Blocked, $client->wallets->block($wallet->id)->status);
    }

    public function testPayoutQuote(): void
    {
        $client = $this->client();
        if ($client->config()->payoutKey === null) {
            self::markTestSkipped('ENIGMOSS_E2E_PAYOUT_KEY is not set.');
        }

        try {
            $quote = $client->payouts->quote(new QuotePayoutRequest('10.00', 'USDT', 'TXYZabcdefghijklmnopqrstuvwxyz1234', 'tron'));
            self::assertSame('USDT', $quote->currencyCode);
        } catch (IpNotAllowedException $e) {
            self::assertSame(403, $e->httpStatus);
        }
    }

    private function client(): EnigmossClient
    {
        if (self::$client !== null) {
            return self::$client;
        }

        $baseUrl = getenv('ENIGMOSS_E2E_BASE_URL');
        $project = getenv('ENIGMOSS_E2E_PROJECT_ID');
        $paymentKey = getenv('ENIGMOSS_E2E_PAYMENT_KEY');
        if (!\is_string($baseUrl) || $baseUrl === '' || !\is_string($project) || !\is_string($paymentKey) || $paymentKey === '') {
            self::markTestSkipped('Set ENIGMOSS_E2E_BASE_URL, ENIGMOSS_E2E_PROJECT_ID and ENIGMOSS_E2E_PAYMENT_KEY to run end-to-end tests.');
        }
        $payoutKey = getenv('ENIGMOSS_E2E_PAYOUT_KEY');

        return self::$client = new EnigmossClient(new ClientConfig(
            baseUrl: $baseUrl,
            projectId: $project,
            paymentKey: new Secret($paymentKey),
            payoutKey: \is_string($payoutKey) && $payoutKey !== '' ? new Secret($payoutKey) : null,
            allowInsecureHttp: str_starts_with($baseUrl, 'http://'),
        ));
    }
}
