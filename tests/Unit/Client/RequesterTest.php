<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Unit\Client;

use Enigmoss\Auth\KeyScope;
use Enigmoss\Exception\ConfigurationException;
use Enigmoss\Exception\ConflictException;
use Enigmoss\Exception\ConnectionException;
use Enigmoss\Exception\DependencyException;
use Enigmoss\Exception\IdempotencyException;
use Enigmoss\Exception\InvalidArgumentException;
use Enigmoss\Exception\IpNotAllowedException;
use Enigmoss\Exception\RateLimitException;
use Enigmoss\Exception\SendPhase;
use Enigmoss\Exception\ServerException;
use Enigmoss\Exception\TransportException;
use Enigmoss\Exception\UnexpectedResponseException;
use Enigmoss\Exception\ValidationException;
use Enigmoss\Http\ClientEvent;
use Enigmoss\Http\ClientEventType;
use Enigmoss\Http\HttpResponse;
use Enigmoss\Http\ObserverInterface;
use Enigmoss\Project\KeyType;
use Enigmoss\RequestOptions;
use Enigmoss\Testing\MockTransport;
use Enigmoss\Tests\Support\ClientFactory;
use Enigmoss\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class RequesterTest extends TestCase
{
    private const PROJECT_DATA = [
        'id' => ClientFactory::PROJECT,
        'name' => 'Shop',
        'key_type' => 'payment',
        'permissions' => ['info_payments'],
        'server_time' => 1767225600,
    ];

    public function testSignedGetMatchesTheNormativeVector(): void
    {
        $transport = (new MockTransport())->queueData(self::PROJECT_DATA);
        $project = ClientFactory::make($transport)->project->get();

        $request = $transport->lastRequest();
        self::assertNotNull($request);
        self::assertSame('GET', $request->method);
        self::assertSame('https://api.example.com/api/v4/project', $request->url);
        self::assertNull($request->body);
        self::assertSame(ClientFactory::PROJECT, $request->headers['X-Project-Uuid']);
        self::assertSame('1767225600', $request->headers['X-Timestamp']);
        self::assertSame('60b66487028d417d160edc7303fd2660168d92cf6d952fd46688bc8c47dc6c51', $request->headers['X-Signature']);
        self::assertArrayNotHasKey('Content-Type', $request->headers);
        self::assertArrayNotHasKey('Idempotency-Key', $request->headers);
        self::assertStringStartsWith('enigmoss-php-sdk/', $request->headers['User-Agent']);
        self::assertSame(KeyType::Payment, $project->keyType);
        self::assertTrue($project->can('info_payments'));
    }

    public function testAnyScopeFallsBackToThePayoutKey(): void
    {
        $transport = (new MockTransport())->queueData(self::PROJECT_DATA);
        $client = new \Enigmoss\EnigmossClient(
            new \Enigmoss\ClientConfig('https://api.example.com', ClientFactory::PROJECT, payoutKey: new \Enigmoss\Secret('test-payout-secret')),
            $transport,
            new FakeClock(),
            new FakeClock(),
        );
        $client->project->get();

        $expected = hash_hmac('sha256', "1767225600\n" . ClientFactory::PROJECT . "\nGET\n/api/v4/project\n", 'test-payout-secret');
        self::assertSame($expected, $transport->lastRequest()?->headers['X-Signature']);
    }

    public function testMissingKeyFailsBeforeSending(): void
    {
        $transport = new MockTransport();

        try {
            ClientFactory::make($transport, withPayout: false)->request('GET', '/payouts', scope: KeyScope::Payout);
            self::fail('Expected ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('payoutKey', $e->getMessage());
        }
        self::assertSame([], $transport->requests());
    }

    public function testPostWithoutBodySendsEmptyJsonObject(): void
    {
        $transport = (new MockTransport())->queueData(['ok' => true]);
        ClientFactory::make($transport)->request('POST', '/payments/x/cancel', idempotent: true);

        $request = $transport->lastRequest();
        self::assertSame('{}', $request?->body);
        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertArrayHasKey('Idempotency-Key', $request->headers);
    }

    public function testClockDriftIsCorrectedOnceWithoutCountingAsRetry(): void
    {
        $clock = new FakeClock();
        $transport = (new MockTransport())
            ->queueError(401, 'authentication_error', 'auth.timestamp_expired', details: ['server_time' => 1767225600 + 900])
            ->queueData(self::PROJECT_DATA);

        ClientFactory::make($transport, $clock, maxRetries: 0)->project->get();

        $requests = $transport->requests();
        self::assertCount(2, $requests);
        self::assertSame('1767225600', $requests[0]->headers['X-Timestamp']);
        self::assertSame('1767226500', $requests[1]->headers['X-Timestamp']);
        self::assertSame([], $clock->sleeps);
    }

    public function testReadsAreRetriedOnGatewayErrorsWithoutEnvelope(): void
    {
        $transport = (new MockTransport())
            ->queue(new HttpResponse(503, [], '<html>Service Unavailable</html>'))
            ->queueData(self::PROJECT_DATA);

        ClientFactory::make($transport)->project->get();

        self::assertCount(2, $transport->requests());
    }

    public function testMoneyCreateIsNotRetriedOnRetryableBusinessError(): void
    {
        $transport = (new MockTransport())
            ->queueError(502, 'dependency_error', 'dependency.gateway', retryable: true)
            ->queueData(['id' => 'never']);

        try {
            ClientFactory::make($transport)->request('POST', '/payouts', ['order_id' => 'o-1'], KeyScope::Payout, idempotent: true);
            self::fail('Expected DependencyException.');
        } catch (DependencyException $e) {
            self::assertTrue($e->retryable);
            self::assertTrue($e->outcomeUnknown());
        }
        self::assertCount(1, $transport->requests());
    }

    public function testMoneyCreateRetriesAfterSentFailureWithSameKeyAndBody(): void
    {
        $clock = new FakeClock();
        $transport = (new MockTransport())
            ->queue(new TransportException('connection reset', SendPhase::Sent))
            ->queueData(['id' => 'p-1'], 201);

        ClientFactory::make($transport, $clock)->request('POST', '/payouts', ['order_id' => 'o-1', 'amount' => '10.00'], KeyScope::Payout, idempotent: true);

        [$first, $second] = $transport->requests();
        self::assertSame($first->headers['Idempotency-Key'], $second->headers['Idempotency-Key']);
        self::assertSame($first->body, $second->body);
        self::assertCount(1, $clock->sleeps);
    }

    public function testNonIdempotentPostIsNotRetriedAfterSending(): void
    {
        $transport = (new MockTransport())
            ->queue(new TransportException('timeout', SendPhase::Sent, true))
            ->queueData(['id' => 'never']);

        try {
            ClientFactory::make($transport)->request('POST', '/events/x/redeliver');
            self::fail('Expected TransportException.');
        } catch (TransportException $e) {
            self::assertTrue($e->outcomeUnknown());
            self::assertTrue($e->timedOut);
        }
        self::assertCount(1, $transport->requests());
    }

    public function testConnectionFailureIsAlwaysRetried(): void
    {
        $transport = (new MockTransport())
            ->queue(new ConnectionException('could not resolve host'))
            ->queueData(['id' => 'e-1'], 202);

        ClientFactory::make($transport)->request('POST', '/events/x/redeliver');

        self::assertCount(2, $transport->requests());
    }

    public function testLongRetryAfterIsThrownImmediately(): void
    {
        $clock = new FakeClock();
        $transport = (new MockTransport())->queueError(429, 'rate_limit_error', 'rate_limit.exceeded', retryable: true, retryAfter: 45);

        try {
            ClientFactory::make($transport, $clock)->project->get();
            self::fail('Expected RateLimitException.');
        } catch (RateLimitException $e) {
            self::assertSame(45, $e->retryAfter);
        }
        self::assertSame([], $clock->sleeps);
    }

    public function testInProgressIsWaitedOutAndConflictKeepsTheFirstError(): void
    {
        $clock = new FakeClock();
        $transport = (new MockTransport())
            ->queueError(409, 'conflict', 'idempotency.in_progress', retryable: true, retryAfter: 5)
            ->queueError(409, 'conflict', 'resource.conflict', details: ['id' => 'p-1', 'api_version' => 'v4']);

        try {
            ClientFactory::make($transport, $clock)->request('POST', '/payouts', ['order_id' => 'o-1'], KeyScope::Payout, idempotent: true);
            self::fail('Expected ConflictException.');
        } catch (ConflictException $e) {
            self::assertNotInstanceOf(IdempotencyException::class, $e);
            self::assertSame('p-1', $e->existingResourceId());
            self::assertInstanceOf(IdempotencyException::class, $e->getPrevious());
        }
        self::assertSame([5.0], $clock->sleeps);
    }

    public function testErrorEnvelopeIsMappedToTypedExceptions(): void
    {
        $transport = (new MockTransport())
            ->queueError(422, 'validation_error', 'validation.failed', violations: [['field' => 'amount', 'rule' => 'max', 'message' => 'Too long.', 'params' => ['max' => '40']]])
            ->queueError(403, 'authorization_error', 'auth.ip_forbidden');
        $client = ClientFactory::make($transport);

        try {
            $client->request('POST', '/payouts', ['order_id' => 'o'], KeyScope::Payout, idempotent: true);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('01JTESTERROR00000000000000', $e->requestId);
            self::assertSame(['max' => '40'], $e->violationsFor('amount')[0]->params);
            self::assertFalse($e->outcomeUnknown());
            self::assertNotNull($e->idempotencyKey);
        }

        $this->expectException(IpNotAllowedException::class);
        $client->request('GET', '/payouts', scope: KeyScope::Payout);
    }

    public function testServerErrorReportsUnknownOutcome(): void
    {
        $transport = (new MockTransport())->queueError(500, 'internal_error', 'internal.server_error');

        try {
            ClientFactory::make($transport)->request('POST', '/transfers', ['order_id' => 'o'], KeyScope::Payout, idempotent: true);
            self::fail('Expected ServerException.');
        } catch (ServerException $e) {
            self::assertTrue($e->outcomeUnknown());
        }
    }

    public function testUnreadableSuccessReportsUnknownOutcome(): void
    {
        $transport = (new MockTransport())->queue(new HttpResponse(201, [], 'not json'));

        try {
            ClientFactory::make($transport)->request('POST', '/transfers', ['order_id' => 'o'], KeyScope::Payout, idempotent: true);
            self::fail('Expected UnexpectedResponseException.');
        } catch (UnexpectedResponseException $e) {
            self::assertTrue($e->outcomeUnknown());
            self::assertSame(201, $e->httpStatus);
        }
    }

    public function testExtraBodyCannotOverrideKnownFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ClientFactory::make(new MockTransport())->request('POST', '/transfers', ['order_id' => 'o'], KeyScope::Payout, options: new RequestOptions(extraBody: ['order_id' => 'x']));
    }

    public function testObserversReceiveEventsAndCannotBreakTheCall(): void
    {
        $recorder = new class implements ObserverInterface {
            /** @var list<ClientEvent> */
            public array $events = [];

            public function onEvent(ClientEvent $event): void
            {
                $this->events[] = $event;
            }
        };
        $broken = new class implements ObserverInterface {
            public function onEvent(ClientEvent $event): void
            {
                throw new \RuntimeException('observer bug');
            }
        };
        $transport = (new MockTransport())->queue(new HttpResponse(503, [], ''))->queueData(self::PROJECT_DATA);

        ClientFactory::make($transport, observers: [$broken, $recorder])->project->get();

        self::assertSame(
            [ClientEventType::RequestSending, ClientEventType::ResponseReceived, ClientEventType::RetryScheduled, ClientEventType::RequestSending, ClientEventType::ResponseReceived],
            array_map(static fn(ClientEvent $e): ClientEventType => $e->type, $recorder->events),
        );
    }

    public function testClientCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);
        serialize(ClientFactory::make(new MockTransport()));
    }
}
