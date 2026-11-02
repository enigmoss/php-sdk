<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Unit\Client;

use Enigmoss\ClientConfig;
use Enigmoss\EnigmossClient;
use Enigmoss\Exception\ErrorCode;
use Enigmoss\Exception\InvalidArgumentException;
use Enigmoss\Exception\IpNotAllowedException;
use Enigmoss\Exception\PermissionDeniedException;
use Enigmoss\Exception\UnexpectedResponseException;
use Enigmoss\Http\HttpResponse;
use Enigmoss\Payment\CreatePaymentRequest;
use Enigmoss\Payout\CreatePayoutRequest;
use Enigmoss\RequestOptions;
use Enigmoss\Secret;
use Enigmoss\Testing\Fixtures;
use Enigmoss\Testing\MockTransport;
use Enigmoss\Tests\Support\ClientFactory;
use Enigmoss\Tests\Support\FakeClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour that follows from the API gateway in front of the server: slow payout creation,
 * gateway errors in the API envelope, headers the gateway owns.
 */
final class GatewayBehaviourTest extends TestCase
{
    public function testPayoutCreationWaitsLongerThanTheDefaultTimeout(): void
    {
        $transport = (new MockTransport())->queueData(Fixtures::payout(), 201)->queueData(Fixtures::payment(), 201);
        $client = ClientFactory::make($transport);

        $client->payouts->create($this->payout());
        $client->payments->create(new CreatePaymentRequest('10.50', 'USDT', 'order-42'));

        [$payout, $payment] = $transport->requests();
        self::assertSame(180.0, $payout->timeout);
        self::assertSame(30.0, $payment->timeout);
    }

    public function testExplicitTimeoutsStillWin(): void
    {
        $transport = (new MockTransport())->queueData(Fixtures::payout(), 201)->queueData(Fixtures::payout(), 201);
        $longer = new EnigmossClient(
            new ClientConfig('https://api.example.com', ClientFactory::PROJECT, payoutKey: new Secret('k'), timeout: 300),
            $transport,
            new FakeClock(),
            new FakeClock(),
        );

        $longer->payouts->create($this->payout());
        $longer->payouts->create($this->payout(), new RequestOptions(timeout: 60));

        [$configured, $perCall] = $transport->requests();
        self::assertSame(300.0, $configured->timeout);
        self::assertSame(60.0, $perCall->timeout);
    }

    public function testGatewayUnavailabilityIsRetriedOnMoneyCreateWithTheSameKey(): void
    {
        $clock = new FakeClock();
        $transport = (new MockTransport())
            ->queueError(502, 'dependency_error', 'dependency.unavailable', 'Service temporarily unavailable.', retryable: true)
            ->queueData(Fixtures::payout(), 201);

        ClientFactory::make($transport, $clock)->payouts->create($this->payout());

        [$first, $second] = $transport->requests();
        self::assertSame($first->headers['Idempotency-Key'], $second->headers['Idempotency-Key']);
        self::assertSame($first->body, $second->body);
        self::assertCount(1, $clock->sleeps);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function gatewayHeaders(): iterable
    {
        foreach (['X-Real-IP', 'X-Forwarded-For', 'Forwarded', 'X-Proxy-Token', 'X-Project'] as $header) {
            yield $header => [$header];
        }
    }

    #[DataProvider('gatewayHeaders')]
    public function testHeadersOwnedByTheGatewayCannotBeSent(string $header): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RequestOptions(headers: [$header => '1.2.3.4']);
    }

    public function testUnknownHostPageHintsAtTheBaseUrl(): void
    {
        $transport = (new MockTransport())->queue(new HttpResponse(404, [], ''));

        try {
            ClientFactory::make($transport)->project->get();
            self::fail('Expected UnexpectedResponseException.');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('baseUrl', $e->getMessage());
        }
    }

    public function testForbiddenDomainIsAPermissionErrorNotAnIpError(): void
    {
        $transport = (new MockTransport())->queueError(403, 'authorization_error', 'auth.domain_forbidden', 'Domain is not allowed for this project.');

        try {
            ClientFactory::make($transport)->project->get();
            self::fail('Expected PermissionDeniedException.');
        } catch (PermissionDeniedException $e) {
            self::assertNotInstanceOf(IpNotAllowedException::class, $e);
            self::assertSame(ErrorCode::DOMAIN_FORBIDDEN, $e->errorCode);
        }
    }

    private function payout(): CreatePayoutRequest
    {
        return new CreatePayoutRequest('withdrawal-42', '100.00', 'USDT', 'TRecipientabcdefghijklmnopqrstuvwx', 'tron');
    }
}
