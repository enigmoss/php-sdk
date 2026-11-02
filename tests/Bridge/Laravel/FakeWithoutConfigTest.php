<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Bridge\Laravel;

use Enigmoss\Bridge\Laravel\EnigmossServiceProvider;
use Enigmoss\Bridge\Laravel\Facades\Enigmoss;
use Enigmoss\Payment\CreatePaymentRequest;
use Enigmoss\Testing\Fixtures;
use Orchestra\Testbench\TestCase;

/**
 * fake() must work in a test suite that never configured ENIGMOSS_* variables.
 */
final class FakeWithoutConfigTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [EnigmossServiceProvider::class];
    }

    public function testFakeNeedsNoConfiguration(): void
    {
        $transport = Enigmoss::fake();
        $transport->queueData(Fixtures::payment(), 201);

        $payment = Enigmoss::payments()->create(new CreatePaymentRequest('10.50', 'USDT', 'order-42'));

        self::assertSame('order-42', $payment->orderId);
        self::assertSame('order-42', $transport->lastJson()['order_id']);
        self::assertCount(1, $transport->sent('POST', '/api/v4/payments'));
    }
}
