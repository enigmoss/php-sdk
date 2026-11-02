<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Bridge\Symfony;

use Enigmoss\EnigmossClient;
use Enigmoss\Event\PaymentEvent;
use Enigmoss\Secret;
use Enigmoss\Testing\Fixtures;
use Enigmoss\Testing\WebhookSimulator;
use Enigmoss\Tests\Support\Symfony\TestKernel;
use Enigmoss\Webhook\WebhookDeduplicatorInterface;
use Enigmoss\Webhook\WebhookVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SymfonyBridgeTest extends TestCase
{
    private static ?TestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new TestKernel('test', false);
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel?->shutdown();
    }

    public function testServicesAreWired(): void
    {
        $container = $this->kernel()->getContainer()->get('test.service_container');
        self::assertInstanceOf(\Symfony\Component\DependencyInjection\ContainerInterface::class, $container);

        $default = $container->get(EnigmossClient::class);
        self::assertInstanceOf(EnigmossClient::class, $default);
        self::assertSame('3f2b8c1e-5d4a-4e7b-9c6f-1a2b3c4d5e6f', $default->config()->projectId);

        $second = $container->get('enigmoss.client.shop2');
        self::assertInstanceOf(EnigmossClient::class, $second);
        self::assertSame('d6a7f0c2-6b1e-4b9a-9f3e-2c4d5e6f7a8b', $second->config()->projectId);

        self::assertInstanceOf(WebhookVerifier::class, $container->get(WebhookVerifier::class));
        $deduplicator = $container->get(WebhookDeduplicatorInterface::class);
        self::assertInstanceOf(WebhookDeduplicatorInterface::class, $deduplicator);
        self::assertTrue($deduplicator->claim('019b7710-0000-7000-8000-00000000dd01'));
        self::assertFalse($deduplicator->claim('019b7710-0000-7000-8000-00000000dd01'));
        $deduplicator->release('019b7710-0000-7000-8000-00000000dd01');
        self::assertTrue($deduplicator->claim('019b7710-0000-7000-8000-00000000dd01'));
    }

    public function testControllerReceivesVerifiedEvent(): void
    {
        $webhook = WebhookSimulator::sign(Fixtures::webhook('payment.paid', Fixtures::paidPayment()), Fixtures::PROJECT_ID, new Secret('test-payment-secret'));

        $response = $this->kernel()->handle($this->request($webhook->body, $webhook->headers));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['type' => 'payment.paid', 'class' => PaymentEvent::class], json_decode((string) $response->getContent(), true));
    }

    public function testForgedWebhookIsAnsweredWith400(): void
    {
        $webhook = WebhookSimulator::sign(Fixtures::webhook('payment.paid', Fixtures::paidPayment()), Fixtures::PROJECT_ID, new Secret('wrong'));

        $response = $this->kernel()->handle($this->request($webhook->body, $webhook->headers));

        self::assertSame(400, $response->getStatusCode());
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $body, array $headers): Request
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[$key === 'CONTENT_TYPE' ? $key : 'HTTP_' . $key] = $value;
        }

        return Request::create('/webhooks/enigmoss', 'POST', [], [], [], $server, $body);
    }

    private function kernel(): TestKernel
    {
        self::assertNotNull(self::$kernel);

        return self::$kernel;
    }
}
