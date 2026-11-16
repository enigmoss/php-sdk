<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Bridge\Laravel;

use Enigmoss\Bridge\Laravel\CacheOriginStore;
use Enigmoss\Bridge\Laravel\EnigmossManager;
use Enigmoss\Bridge\Laravel\EnigmossServiceProvider;
use Enigmoss\Bridge\Laravel\Events\WebhookReceived;
use Enigmoss\Bridge\Laravel\Facades\Enigmoss;
use Enigmoss\Bridge\Laravel\Testing\InteractsWithEnigmossWebhooks;
use Enigmoss\EnigmossClient;
use Enigmoss\Event\PaymentEvent;
use Enigmoss\Payment\CreatePaymentRequest;
use Enigmoss\Secret;
use Enigmoss\Testing\Fixtures;
use Enigmoss\Testing\WebhookSimulator;
use Enigmoss\Webhook\WebhookVerifier;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase;

final class LaravelBridgeTest extends TestCase
{
    use InteractsWithEnigmossWebhooks;

    protected function getPackageProviders($app): array
    {
        return [EnigmossServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Enigmoss' => Enigmoss::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);
        $config->set('enigmoss.connections.default', [
            'base_url' => 'https://api.example.com',
            'project_id' => Fixtures::PROJECT_ID,
            'payment_key' => 'test-payment-secret',
            'payout_key' => 'test-payout-secret',
        ]);
        $config->set('enigmoss.connections.second', [
            'base_url' => 'https://api.example.com',
            'project_id' => 'd6a7f0c2-6b1e-4b9a-9f3e-2c4d5e6f7a8b',
            'payment_key' => 'second-secret',
        ]);
        $config->set('enigmoss.connections.with-fallback', [
            'base_url' => 'https://api.example.com',
            'project_id' => Fixtures::PROJECT_ID,
            'payment_key' => 'test-payment-secret',
            'fallback_base_url' => 'https://api.example.org/',
        ]);
        $config->set('cache.default', 'array');
        $config->set('queue.default', 'sync');
    }

    protected function defineRoutes($router): void
    {
        Route::__callStatic('enigmossWebhooks', ['webhooks/enigmoss']);
    }

    public function testClientAndVerifierAreResolvable(): void
    {
        self::assertInstanceOf(EnigmossClient::class, $this->application()->make(EnigmossClient::class));
        self::assertInstanceOf(WebhookVerifier::class, $this->application()->make(WebhookVerifier::class));
        self::assertSame('d6a7f0c2-6b1e-4b9a-9f3e-2c4d5e6f7a8b', Enigmoss::connection('second')->config()->projectId);
    }

    public function testFakeTransportBacksTheFacade(): void
    {
        $transport = Enigmoss::fake();
        $transport->queueData(Fixtures::payment(), 201);

        $payment = Enigmoss::payments()->create(new CreatePaymentRequest('10.50', 'USDT', 'order-42'));

        self::assertSame('order-42', $payment->orderId);
        self::assertSame('https://api.example.com/api/v4/payments', $transport->lastRequest()?->url);
    }

    public function testSignedWebhookIsAcceptedOnceAndDispatched(): void
    {
        Event::fake([WebhookReceived::class]);
        $webhook = WebhookSimulator::sign(Fixtures::webhook('payment.paid', Fixtures::paidPayment()), Fixtures::PROJECT_ID, new Secret('test-payment-secret'));

        $this->call('POST', '/webhooks/enigmoss', [], [], [], $this->server($webhook->headers), $webhook->body)
            ->assertOk()->assertJson(['status' => 'accepted']);
        $this->call('POST', '/webhooks/enigmoss', [], [], [], $this->server($webhook->headers), $webhook->body)
            ->assertOk()->assertJson(['status' => 'duplicate']);

        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        Event::assertDispatched(WebhookReceived::class, static fn(WebhookReceived $e): bool => $e->event instanceof PaymentEvent && $e->event->payment()->isFinal);
    }

    public function testForgedWebhookIsRejected(): void
    {
        Event::fake([WebhookReceived::class]);
        $webhook = WebhookSimulator::sign(Fixtures::webhook('payment.paid', Fixtures::paidPayment()), Fixtures::PROJECT_ID, new Secret('wrong-secret'));

        $this->call('POST', '/webhooks/enigmoss', [], [], [], $this->server($webhook->headers), $webhook->body)
            ->assertStatus(400)->assertJson(['reason' => 'signature_mismatch']);

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function testFailedHandlingReleasesTheClaimSoTheRedeliveryIsHandled(): void
    {
        $calls = 0;
        Event::listen(WebhookReceived::class, static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('handler bug');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->postEnigmossWebhook('payment.paid', Fixtures::paidPayment(), eventId: '019b7710-0000-7000-8000-0000000000f1');
            self::fail('The failing handler must surface as an error (HTTP 500).');
        } catch (\RuntimeException $e) {
            self::assertSame('handler bug', $e->getMessage());
        }

        $this->postEnigmossWebhook('payment.paid', Fixtures::paidPayment(), eventId: '019b7710-0000-7000-8000-0000000000f1')
            ->assertOk()->assertJson(['status' => 'accepted']);
        self::assertSame(2, $calls);
    }

    public function testAFallbackBaseUrlKeepsTheLearnedOriginInTheCache(): void
    {
        $plain = Enigmoss::connection()->config();
        self::assertNull($plain->fallbackOrigin);
        self::assertNull($plain->originStore);

        $config = Enigmoss::connection('with-fallback')->config();
        self::assertSame('https://api.example.org', $config->fallbackOrigin);
        self::assertInstanceOf(CacheOriginStore::class, $config->originStore);

        $config->originStore->set('enigmoss_origin_test', '{"origin":"https://api2.example.com"}', 60);
        self::assertSame('{"origin":"https://api2.example.com"}', $config->originStore->get('enigmoss_origin_test'));
        self::assertSame('{"origin":"https://api2.example.com"}', $this->application()->make(\Illuminate\Contracts\Cache\Repository::class)->get('enigmoss_origin_test'));
        self::assertNull($config->originStore->get('enigmoss_origin_missing'));
    }

    public function testCheckCommandReportsBothKeys(): void
    {
        $transport = $this->application()->make(EnigmossManager::class)->fake();
        $transport->queueData(Fixtures::project(['server_time' => time()]));
        $transport->queueData(Fixtures::project(['key_type' => 'payout', 'permissions' => ['payout_create'], 'server_time' => time()]));

        $command = $this->artisan('enigmoss:check');
        self::assertInstanceOf(PendingCommand::class, $command);
        $command->expectsOutputToContain('Payment key: OK')
            ->expectsOutputToContain('API origin: https://api.example.com')
            ->expectsOutputToContain('Payout key: OK')
            ->assertSuccessful();
    }

    private function application(): \Illuminate\Foundation\Application
    {
        self::assertNotNull($this->app);

        return $this->app;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function server(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[$key === 'CONTENT_TYPE' ? $key : 'HTTP_' . $key] = $value;
        }

        return $server;
    }
}
