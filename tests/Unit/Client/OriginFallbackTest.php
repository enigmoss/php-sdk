<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Unit\Client;

use Enigmoss\ClientConfig;
use Enigmoss\EnigmossClient;
use Enigmoss\Exception\AuthenticationException;
use Enigmoss\Exception\ConnectionException;
use Enigmoss\Exception\DependencyException;
use Enigmoss\Exception\EnigmossException;
use Enigmoss\Exception\NotFoundException;
use Enigmoss\Exception\RateLimitException;
use Enigmoss\Exception\SendPhase;
use Enigmoss\Exception\ServerException;
use Enigmoss\Exception\TransportException;
use Enigmoss\Exception\UnexpectedResponseException;
use Enigmoss\Http\ClientEventType;
use Enigmoss\Http\HttpRequest;
use Enigmoss\Http\HttpResponse;
use Enigmoss\Http\ObserverInterface;
use Enigmoss\Http\OriginStoreInterface;
use Enigmoss\Payout\CreatePayoutRequest;
use Enigmoss\Secret;
use Enigmoss\Testing\Fixtures;
use Enigmoss\Tests\Support\ArrayOriginStore;
use Enigmoss\Tests\Support\ClientFactory;
use Enigmoss\Tests\Support\EventRecorder;
use Enigmoss\Tests\Support\FakeClock;
use Enigmoss\Tests\Support\RoutedTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The API-origin fallback: retries on the configured origin first, then one signed lookup on the
 * fallback host, then the same request on the origin it names.
 */
final class OriginFallbackTest extends TestCase
{
    private const PRIMARY = 'https://api.example.com';
    private const FALLBACK = 'https://api.example.org';
    private const MOVED = 'https://api2.example.com';

    private const PROJECT_DATA = [
        'id' => ClientFactory::PROJECT,
        'name' => 'Shop',
        'key_type' => 'payment',
        'permissions' => ['info_payments'],
        'server_time' => 1767225600,
    ];

    public function testARetryThatHelpsOnTheConfiguredOriginNeverAsksTheFallbackHost(): void
    {
        $transport = (new RoutedTransport())->on(self::PRIMARY, self::unreachable(), self::unreachable(), self::ok(self::PROJECT_DATA));
        $client = $this->client($transport);

        $client->project->get();

        self::assertSame([self::PRIMARY, self::PRIMARY, self::PRIMARY], $transport->route());
        self::assertSame(self::PRIMARY, $client->config()->origin);
    }

    public function testMovesToTheOriginTheFallbackHostNamesAndRemembersIt(): void
    {
        $events = new EventRecorder();
        $transport = (new RoutedTransport())
            ->on(self::PRIMARY, self::unreachable())
            ->on(self::FALLBACK, self::brand(self::MOVED))
            ->on(self::MOVED, self::ok(self::PROJECT_DATA));
        $client = $this->client($transport, observers: [$events]);

        $project = $client->project->get();

        self::assertSame('Shop', $project->name);
        self::assertSame([self::PRIMARY, self::PRIMARY, self::PRIMARY, self::FALLBACK, self::MOVED], $transport->route());

        $probe = $transport->to(self::FALLBACK)[0];
        self::assertSame('GET', $probe->method);
        self::assertMatchesRegularExpression('~^' . preg_quote(self::FALLBACK, '~') . '/api/v4/project/platform-brand\?nonce=[0-9a-f]{32}$~D', $probe->url);
        self::assertNull($probe->body);
        self::assertSame(3.0, $probe->timeout);
        self::assertSame(3.0, $probe->connectTimeout);
        self::assertSame(ClientFactory::PROJECT, $probe->headers['X-Project-Uuid']);
        self::assertSame(
            hash_hmac('sha256', $probe->headers['X-Timestamp'] . "\n" . ClientFactory::PROJECT . "\nGET\n" . substr($probe->url, \strlen(self::FALLBACK)) . "\n", ClientFactory::PAYMENT_SECRET),
            $probe->headers['X-Signature'],
            'The nonce is part of the signed request URI.',
        );
        self::assertArrayNotHasKey('Idempotency-Key', $probe->headers);

        // The next call goes straight to the remembered origin.
        $client->project->get();
        self::assertCount(3, $transport->to(self::PRIMARY));
        self::assertCount(1, $transport->to(self::FALLBACK));
        self::assertCount(2, $transport->to(self::MOVED));
        self::assertSame(self::MOVED, $client->config()->origin);
        self::assertSame(self::FALLBACK, $client->config()->fallbackOrigin);

        $changes = $events->ofType(ClientEventType::OriginChanged);
        self::assertCount(1, $changes);
        self::assertSame(self::MOVED, $changes[0]->origin);
        self::assertSame(self::PRIMARY, $changes[0]->previousOrigin);
        self::assertSame(
            [
                ClientEventType::RequestSending, ClientEventType::RetryScheduled,
                ClientEventType::RequestSending, ClientEventType::RetryScheduled,
                ClientEventType::RequestSending, ClientEventType::OriginChanged,
                ClientEventType::RequestSending, ClientEventType::ResponseReceived,
                ClientEventType::RequestSending, ClientEventType::ResponseReceived,
            ],
            $events->types(),
        );
    }

    public function testAMoneyPostIsRepeatedOnTheNewOriginWithTheSameKeyAndBody(): void
    {
        $clock = new FakeClock();
        $transport = (new RoutedTransport())
            ->on(self::PRIMARY, self::unreachable())
            ->on(self::FALLBACK, static function (HttpRequest $request) use ($clock): HttpResponse {
                $clock->time += 2.0; // the lookup takes its time

                return self::brand(self::MOVED)($request);
            })
            ->on(self::MOVED, self::ok(Fixtures::payout(), 201));
        $client = $this->client($transport, $clock);

        $payout = $client->payouts->create(new CreatePayoutRequest('withdrawal-42', '100.00', 'USDT', 'TRecipientabcdefghijklmnopqrstuvwx', 'tron'));

        self::assertSame('withdrawal-42', $payout->orderId);
        $first = $transport->to(self::PRIMARY)[0];
        $moved = $transport->to(self::MOVED);
        self::assertCount(1, $moved);
        self::assertSame(self::MOVED . '/api/v4/payouts', $moved[0]->url);
        self::assertSame($first->body, $moved[0]->body);
        self::assertNotNull($first->headers['Idempotency-Key'] ?? null);
        self::assertSame($first->headers['Idempotency-Key'], $moved[0]->headers['Idempotency-Key']);
        // A fresh timestamp and a signature made for it, with the payout key.
        self::assertNotSame($first->headers['X-Timestamp'], $moved[0]->headers['X-Timestamp']);
        self::assertSame(
            hash_hmac('sha256', $moved[0]->headers['X-Timestamp'] . "\n" . ClientFactory::PROJECT . "\nPOST\n/api/v4/payouts\n" . $moved[0]->body, ClientFactory::PAYOUT_SECRET),
            $moved[0]->headers['X-Signature'],
        );
    }

    public function testTheAttemptCounterStartsOverOnTheNewOrigin(): void
    {
        $transport = (new RoutedTransport())
            ->on(self::PRIMARY, self::unreachable())
            ->on(self::FALLBACK, self::brand(self::MOVED))
            ->on(self::MOVED, new HttpResponse(503, [], ''), new HttpResponse(503, [], ''), self::ok(self::PROJECT_DATA));

        $this->client($transport)->project->get();

        self::assertCount(3, $transport->to(self::PRIMARY));
        self::assertCount(3, $transport->to(self::MOVED));
    }

    public function testThereIsOneFallbackPerCall(): void
    {
        $transport = (new RoutedTransport())
            ->on(self::PRIMARY, self::unreachable())
            ->on(self::FALLBACK, self::brand(self::MOVED))
            ->on(self::MOVED, self::unreachable('new origin is down'));

        try {
            $this->client($transport)->project->get();
            self::fail('Expected ConnectionException.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('new origin is down', $e->getMessage());
        }
        self::assertCount(1, $transport->to(self::FALLBACK));
        self::assertCount(3, $transport->to(self::MOVED));
    }

    /**
     * @return iterable<string, array{HttpResponse|\Closure(HttpRequest): HttpResponse, class-string<EnigmossException>, string}>
     */
    public static function fallbackFailures(): iterable
    {
        yield '404' => [self::error(404, 'not_found', 'resource.not_found'), NotFoundException::class, 'resource.not_found'];
        yield '401' => [self::error(401, 'authentication_error', 'auth.signature_invalid'), AuthenticationException::class, 'auth.signature_invalid'];
        yield '429' => [self::error(429, 'rate_limit_error', 'rate_limit.exceeded'), RateLimitException::class, 'rate_limit.exceeded'];
        yield '500' => [self::error(500, 'internal_error', 'internal.server_error'), ServerException::class, 'internal.server_error'];
        yield '503 proxy page' => [new HttpResponse(503, [], '<html>Service Unavailable</html>'), DependencyException::class, 'HTTP 503'];
        yield '404 proxy page' => [new HttpResponse(404, [], 'Not Found'), UnexpectedResponseException::class, 'fallbackBaseUrl'];
        yield '302' => [new HttpResponse(302, ['Location' => 'https://elsewhere.example.com'], ''), UnexpectedResponseException::class, 'HTTP 302'];
        yield '200 without api' => [self::brand(null), UnexpectedResponseException::class, 'no data.api.origin'];
        yield '200 not json' => [self::signed('<html>'), UnexpectedResponseException::class, 'no data.api.origin'];
        yield 'http origin' => [self::brand('http://api2.example.com'), UnexpectedResponseException::class, 'not a plain https origin'];
        yield 'ip origin' => [self::brand('https://203.0.113.10'), UnexpectedResponseException::class, 'not a plain https origin'];
        yield 'origin with a port' => [self::brand('https://api2.example.com:8443'), UnexpectedResponseException::class, 'not a plain https origin'];
        yield 'origin with a path' => [self::brand('https://api2.example.com/api/v4'), UnexpectedResponseException::class, 'not a plain https origin'];
        yield 'origin with userinfo' => [self::brand('https://key@api2.example.com'), UnexpectedResponseException::class, 'not a plain https origin'];
        yield 'localhost origin' => [self::brand('https://localhost'), UnexpectedResponseException::class, 'not a plain https origin'];
        yield 'local origin' => [self::brand('https://api.local'), UnexpectedResponseException::class, 'not a plain https origin'];
        yield 'loopback http without the flag' => [self::brand('http://127.0.0.1:8080'), UnexpectedResponseException::class, 'not a plain https origin'];
        yield 'unsigned' => [self::ok(Fixtures::platformBrand(['api' => ['origin' => self::MOVED]])), UnexpectedResponseException::class, 'not signed'];
        yield 'signed with another key' => [self::brand(self::MOVED, key: 'another-secret'), UnexpectedResponseException::class, 'does not match the project key'];
        yield 'signature of another request' => [self::brand(self::MOVED, requestSignature: str_repeat('ab', 32)), UnexpectedResponseException::class, 'does not match the project key'];
        yield 'stale timestamp' => [self::brand(self::MOVED, timestampShift: -301), UnexpectedResponseException::class, 'X-Response-Timestamp'];
        yield 'timestamp from the future' => [self::brand(self::MOVED, timestampShift: 301), UnexpectedResponseException::class, 'X-Response-Timestamp'];
    }

    /**
     * @param HttpResponse|(\Closure(HttpRequest): HttpResponse) $reply
     * @param class-string<EnigmossException>                    $class
     */
    #[DataProvider('fallbackFailures')]
    public function testAnErrorOfTheFallbackHostIsThrownAndNothingIsRemembered(HttpResponse|\Closure $reply, string $class, string $text): void
    {
        $clock = new FakeClock();
        $events = new EventRecorder();
        $original = self::unreachable();
        $transport = (new RoutedTransport())->on(self::PRIMARY, $original)->on(self::FALLBACK, $reply);
        $client = $this->client($transport, $clock, observers: [$events]);

        try {
            $client->payouts->create(new CreatePayoutRequest('withdrawal-42', '100.00', 'USDT', 'TRecipientabcdefghijklmnopqrstuvwx', 'tron'));
            self::fail('Expected ' . $class . '.');
        } catch (EnigmossException $e) {
            self::assertInstanceOf($class, $e);
            self::assertStringStartsWith('API origin fallback failed: ', $e->getMessage());
            self::assertStringContainsString($text, $e->getMessage());
            self::assertSame($original, $e->getPrevious());
            // The payout request never left: whatever the fallback host answered, nothing was created.
            self::assertFalse($e->outcomeUnknown());
        }

        self::assertSame([self::PRIMARY, self::PRIMARY, self::PRIMARY, self::FALLBACK], $transport->route());
        self::assertSame(self::PRIMARY, $client->config()->origin);
        $last = $events->events[\count($events->events) - 1];
        self::assertSame(ClientEventType::RequestFailed, $last->type);
        self::assertInstanceOf($class, $last->error);
        self::assertSame([], $events->ofType(ClientEventType::OriginChanged));

        // Inside the pause the fallback host is not asked again: the caller gets the network error.
        $clock->time += 200;

        try {
            $client->project->get();
            self::fail('Expected ConnectionException.');
        } catch (ConnectionException $e) {
            self::assertSame($original, $e);
        }
        self::assertCount(1, $transport->to(self::FALLBACK));

        // After the pause it is asked again.
        $clock->time += 301;

        try {
            $client->project->get();
            self::fail('Expected ' . $class . '.');
        } catch (EnigmossException $e) {
            self::assertInstanceOf($class, $e);
        }
        self::assertCount(2, $transport->to(self::FALLBACK));
    }

    public function testTheSameOriginFromTheFallbackHostGivesTheOriginalError(): void
    {
        $clock = new FakeClock();
        $original = self::unreachable();
        $transport = (new RoutedTransport())->on(self::PRIMARY, $original)->on(self::FALLBACK, self::brand('https://API.example.com:443'));
        $client = $this->client($transport, $clock);

        try {
            $client->project->get();
            self::fail('Expected ConnectionException.');
        } catch (ConnectionException $e) {
            self::assertSame($original, $e);
            self::assertFalse($e->outcomeUnknown());
        }
        self::assertSame(self::PRIMARY, $client->config()->origin);

        // Paused like any fallback that gave no new origin.
        try {
            $client->project->get();
        } catch (ConnectionException) {
        }
        self::assertCount(1, $transport->to(self::FALLBACK));
    }

    public function testAnUnreachableFallbackHostGivesTheOriginalError(): void
    {
        $original = self::unreachable();
        $transport = (new RoutedTransport())
            ->on(self::PRIMARY, $original)
            ->on(self::FALLBACK, new TransportException('timeout', SendPhase::Sent, true));
        $client = $this->client($transport);

        for ($call = 1; $call <= 2; ++$call) {
            try {
                $client->project->get();
                self::fail('Expected ConnectionException.');
            } catch (ConnectionException $e) {
                self::assertSame($original, $e);
            }
        }
        self::assertCount(1, $transport->to(self::FALLBACK));
    }

    /**
     * @return iterable<string, array{TransportException}>
     */
    public static function failuresThatDoNotProveTheHostIsUnreachable(): iterable
    {
        // What the PSR-18 and Symfony bridges and cautious custom transports report.
        yield 'not sent, no proof' => [new ConnectionException('could not connect')];
        yield 'unknown phase' => [new TransportException('failed', SendPhase::Unknown)];
        yield 'response timeout' => [new TransportException('timed out', SendPhase::Sent, true)];
        yield 'connection lost after sending' => [new TransportException('reset', SendPhase::Sent)];
        yield 'flag on a sent request' => [new TransportException('reset', SendPhase::Sent, false, null, true)];
    }

    #[DataProvider('failuresThatDoNotProveTheHostIsUnreachable')]
    public function testOnlyAPositivelyUnreachableHostStartsTheFallback(TransportException $failure): void
    {
        $transport = (new RoutedTransport())->on(self::PRIMARY, $failure)->on(self::FALLBACK, self::brand(self::MOVED));

        try {
            $this->client($transport)->project->get();
            self::fail('Expected TransportException.');
        } catch (TransportException $e) {
            self::assertSame($failure, $e);
        }
        self::assertSame([], $transport->to(self::FALLBACK));
    }

    public function testWithoutAFallbackBaseUrlNothingChanges(): void
    {
        $original = self::unreachable();
        $transport = (new RoutedTransport())->on(self::PRIMARY, $original);
        $clock = new FakeClock();
        $client = new EnigmossClient(
            new ClientConfig(self::PRIMARY, ClientFactory::PROJECT, new Secret(ClientFactory::PAYMENT_SECRET)),
            $transport,
            $clock,
            $clock,
        );

        try {
            $client->project->get();
            self::fail('Expected ConnectionException.');
        } catch (ConnectionException $e) {
            self::assertSame($original, $e);
        }
        self::assertSame([self::PRIMARY, self::PRIMARY, self::PRIMARY], $transport->route());
        self::assertNull($client->config()->fallbackOrigin);
    }

    public function testTheFallbackIsSkippedWhenTheCallHasLessThanThreeSecondsLeft(): void
    {
        $clock = new FakeClock();
        // Every attempt burns its whole 30 s: after three of them 20 s of backoff budget is left,
        // and the last attempt eats all but 2 s of it.
        $attempts = 0;
        $slow = static function () use ($clock, &$attempts): HttpResponse {
            $clock->time += ++$attempts < 3 ? 30.0 : 48.0;

            throw self::unreachable();
        };
        $transport = (new RoutedTransport())->on(self::PRIMARY, $slow)->on(self::FALLBACK, self::brand(self::MOVED));
        $client = $this->client($transport, $clock);

        try {
            $client->project->get();
            self::fail('Expected ConnectionException.');
        } catch (ConnectionException) {
        }
        self::assertSame([], $transport->to(self::FALLBACK));

        // Skipping is not a failed fallback: the next call may ask at once.
        $transport->on(self::PRIMARY, self::unreachable())->on(self::MOVED, self::ok(self::PROJECT_DATA));
        $client->project->get();
        self::assertCount(1, $transport->to(self::FALLBACK));
    }

    public function testAnExpiredTimestampOnTheLookupIsCorrectedOnce(): void
    {
        $clock = new FakeClock();
        $serverTime = (int) $clock->time + 1000;
        $transport = (new RoutedTransport())
            ->on(self::PRIMARY, self::unreachable())
            ->on(
                self::FALLBACK,
                self::error(401, 'authentication_error', 'auth.timestamp_expired', ['server_time' => $serverTime]),
                self::brand(self::MOVED),
            )
            ->on(self::MOVED, self::ok(self::PROJECT_DATA));
        $client = $this->client($transport, $clock);

        $client->project->get();

        $probes = $transport->to(self::FALLBACK);
        self::assertCount(2, $probes);
        // The repeat is a new request: its own nonce and therefore its own signature.
        self::assertNotSame($probes[0]->url, $probes[1]->url);
        self::assertNotSame($probes[0]->headers['X-Signature'], $probes[1]->headers['X-Signature']);
        self::assertNotSame($probes[0]->headers['X-Timestamp'], $probes[1]->headers['X-Timestamp']);
        self::assertGreaterThanOrEqual($serverTime, (int) $probes[1]->headers['X-Timestamp']);
        self::assertSame(self::MOVED, $client->config()->origin);
    }

    public function testARecordedAnswerCannotBeReplayedEvenWhenTheFallbackHostMovesTheClock(): void
    {
        // Somebody recorded a genuine signed answer, now answers on the fallback host and uses
        // the unsigned server_time of a 401 to put the client's clock back to that moment.
        $clock = new FakeClock();
        $recordedAt = (int) $clock->time - 5000;
        $recordedUri = '/api/v4/project/platform-brand?nonce=' . str_repeat('0', 32);
        $recordedRequestSignature = hash_hmac('sha256', $recordedAt . "\n" . ClientFactory::PROJECT . "\nGET\n" . $recordedUri . "\n", ClientFactory::PAYMENT_SECRET);
        $recordedBody = (string) json_encode(['data' => Fixtures::platformBrand(['api' => ['origin' => self::MOVED]])], JSON_UNESCAPED_SLASHES);
        $recorded = new HttpResponse(200, [
            'X-Response-Timestamp' => (string) $recordedAt,
            'X-Response-Signature' => hash_hmac('sha256', $recordedAt . "\n" . $recordedRequestSignature . "\n" . $recordedBody, ClientFactory::PAYMENT_SECRET),
        ], $recordedBody);
        $transport = (new RoutedTransport())
            ->on(self::PRIMARY, self::unreachable())
            ->on(self::FALLBACK, self::error(401, 'authentication_error', 'auth.timestamp_expired', ['server_time' => $recordedAt]), $recorded);
        $client = $this->client($transport, $clock);

        try {
            $client->project->get();
            self::fail('Expected UnexpectedResponseException.');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('does not match the project key', $e->getMessage());
        }

        // The client did sign for the recorded second, and still the answer does not fit.
        self::assertSame((string) $recordedAt, $transport->to(self::FALLBACK)[1]->headers['X-Timestamp']);
        self::assertSame(self::PRIMARY, $client->config()->origin);
    }

    public function testEveryLookupCarriesItsOwnNonce(): void
    {
        $clock = new FakeClock();
        $transport = (new RoutedTransport())->on(self::PRIMARY, self::unreachable())->on(self::FALLBACK, self::error(404, 'not_found', 'resource.not_found'));
        $client = $this->client($transport, $clock);

        for ($call = 1; $call <= 2; ++$call) {
            try {
                $client->project->get();
            } catch (NotFoundException) {
            }
            $clock->time += 301;
        }

        $probes = $transport->to(self::FALLBACK);
        self::assertCount(2, $probes);
        self::assertNotSame($probes[0]->url, $probes[1]->url);
    }

    public function testASecondExpiredTimestampIsTheErrorOfTheFallback(): void
    {
        $clock = new FakeClock();
        $expired = self::error(401, 'authentication_error', 'auth.timestamp_expired', ['server_time' => (int) $clock->time + 1000]);
        $transport = (new RoutedTransport())->on(self::PRIMARY, self::unreachable())->on(self::FALLBACK, $expired);

        try {
            $this->client($transport, $clock)->project->get();
            self::fail('Expected AuthenticationException.');
        } catch (AuthenticationException $e) {
            self::assertStringStartsWith('API origin fallback failed: ', $e->getMessage());
        }
        self::assertCount(2, $transport->to(self::FALLBACK));
    }

    public function testTheOriginIsRememberedForRefreshAfterClampedToOneHourAndSevenDays(): void
    {
        foreach ([[60, 3600], [86400, 86400], [10_000_000, 604_800]] as [$refreshAfter, $ttl]) {
            $clock = new FakeClock();
            $transport = (new RoutedTransport())
                ->on(self::PRIMARY, self::unreachable())
                ->on(self::FALLBACK, self::brand(self::MOVED, $refreshAfter))
                ->on(self::MOVED, self::ok(self::PROJECT_DATA));
            $client = $this->client($transport, $clock);
            $client->project->get();
            $movedAt = $clock->time;

            $clock->time = $movedAt + $ttl - 1;
            self::assertSame(self::MOVED, $client->config()->origin, "refresh_after {$refreshAfter}");

            // Expired: the configured origin is tried again (and works again in this test).
            $clock->time = $movedAt + $ttl;
            self::assertSame(self::PRIMARY, $client->config()->origin, "refresh_after {$refreshAfter}");
            $transport->on(self::PRIMARY, self::ok(self::PROJECT_DATA));
            $client->project->get();
            self::assertCount(1, $transport->to(self::FALLBACK));
            self::assertCount(1, $transport->to(self::MOVED));
        }
    }

    public function testACallForThePlatformBrandGetsTheAnswerOfTheFallbackHost(): void
    {
        $transport = (new RoutedTransport())->on(self::PRIMARY, self::unreachable())->on(self::FALLBACK, self::brand(self::MOVED, 7200));
        $client = $this->client($transport);

        $brand = $client->project->platformBrand();

        self::assertSame(self::MOVED, $brand->apiOrigin);
        self::assertSame(7200, $brand->refreshAfter);
        self::assertSame('https://pay.example.com', $brand->payformOrigin);
        // No second request: the lookup was the call.
        self::assertSame([self::PRIMARY, self::PRIMARY, self::PRIMARY, self::FALLBACK], $transport->route());
        self::assertSame(self::MOVED, $client->config()->origin);
    }

    public function testACallForThePlatformBrandSucceedsEvenWhenTheOriginIsTheSame(): void
    {
        $transport = (new RoutedTransport())->on(self::PRIMARY, self::unreachable())->on(self::FALLBACK, self::brand(self::PRIMARY));
        $client = $this->client($transport);

        self::assertSame(self::PRIMARY, $client->project->platformBrand()->apiOrigin);
        self::assertSame(self::PRIMARY, $client->config()->origin);
    }

    public function testALoopbackHttpOriginIsAcceptedOnlyUnderAllowInsecureHttp(): void
    {
        $clock = new FakeClock();
        $transport = (new RoutedTransport())
            ->on('http://localhost:8080', self::unreachable())
            ->on('http://localhost:8081', self::brand('http://127.0.0.1:8082'))
            ->on('http://127.0.0.1:8082', self::ok(self::PROJECT_DATA));
        $client = new EnigmossClient(
            new ClientConfig(
                'http://localhost:8080',
                ClientFactory::PROJECT,
                new Secret(ClientFactory::PAYMENT_SECRET),
                allowInsecureHttp: true,
                fallbackBaseUrl: 'http://localhost:8081',
            ),
            $transport,
            $clock,
            $clock,
        );

        $client->project->get();

        self::assertSame('http://127.0.0.1:8082', $client->config()->origin);
    }

    public function testTheOriginStoreOutlivesTheClient(): void
    {
        $clock = new FakeClock();
        $store = new ArrayOriginStore($clock);
        $transport = (new RoutedTransport())
            ->on(self::PRIMARY, self::unreachable())
            ->on(self::FALLBACK, self::brand(self::MOVED))
            ->on(self::MOVED, self::ok(self::PROJECT_DATA));

        // "Request one" under PHP-FPM: the client moves and dies with the request.
        $this->client($transport, $clock, $store)->project->get();
        self::assertCount(1, $store->values);

        // "Request two": a new client starts on the stored origin, without touching the dead one.
        $second = $this->client($transport, $clock, $store);
        $second->project->get();
        self::assertSame(self::MOVED, $second->config()->origin);
        self::assertCount(3, $transport->to(self::PRIMARY));
        self::assertCount(1, $transport->to(self::FALLBACK));
        self::assertCount(2, $transport->to(self::MOVED));

        // Another project, or another base URL, has its own entry.
        $other = $this->client($transport, $clock, $store)->withCredentials('d6a7f0c2-6b1e-4b9a-9f3e-2c4d5e6f7a8b', new Secret('second-secret'));
        self::assertSame(self::PRIMARY, $other->config()->origin);

        // The stored origin expires with refresh_after.
        $clock->time += 86400;
        self::assertSame(self::PRIMARY, $this->client($transport, $clock, $store)->config()->origin);
    }

    public function testAWorkerPicksUpTheOriginAnotherProcessStored(): void
    {
        $clock = new FakeClock();
        $store = new ArrayOriginStore($clock);
        $transport = (new RoutedTransport())
            ->on(self::PRIMARY, self::ok(self::PROJECT_DATA), self::unreachable())
            ->on(self::FALLBACK, self::brand(self::MOVED))
            ->on(self::MOVED, self::ok(self::PROJECT_DATA));

        // A long-running worker that has read the (empty) store already.
        $worker = $this->client($transport, $clock, $store);
        $worker->project->get();

        // Another process moves.
        $this->client($transport, $clock, $store)->project->get();
        self::assertCount(1, $transport->to(self::FALLBACK));

        // The worker fails on the configured origin, finds the stored origin and does not ask again.
        $worker->project->get();
        self::assertCount(1, $transport->to(self::FALLBACK));
        self::assertSame(self::MOVED, $worker->config()->origin);
    }

    public function testThePauseIsSharedThroughTheStoreToo(): void
    {
        $clock = new FakeClock();
        $store = new ArrayOriginStore($clock);
        $transport = (new RoutedTransport())
            ->on(self::PRIMARY, self::unreachable())
            ->on(self::FALLBACK, self::error(404, 'not_found', 'resource.not_found'));

        try {
            $this->client($transport, $clock, $store)->project->get();
            self::fail('Expected NotFoundException.');
        } catch (NotFoundException) {
        }

        try {
            $this->client($transport, $clock, $store)->project->get();
            self::fail('Expected ConnectionException.');
        } catch (ConnectionException) {
        }
        self::assertCount(1, $transport->to(self::FALLBACK));
        // Only the time is stored: neither the response nor the error.
        self::assertSame(['origin' => null, 'until' => null], array_diff_key((array) json_decode(array_values($store->values)[0][0], true), ['paused' => 0]));
    }

    public function testABrokenOrPoisonedStoreNeverBreaksACall(): void
    {
        $clock = new FakeClock();
        $broken = new class implements OriginStoreInterface {
            public function get(string $key): ?string
            {
                throw new \RuntimeException('cache is down');
            }

            public function set(string $key, string $value, int $ttl): void
            {
                throw new \RuntimeException('cache is down');
            }
        };
        $transport = (new RoutedTransport())
            ->on(self::PRIMARY, self::unreachable())
            ->on(self::FALLBACK, self::brand(self::MOVED))
            ->on(self::MOVED, self::ok(self::PROJECT_DATA));
        $client = $this->client($transport, $clock, $broken);
        $client->project->get();
        self::assertSame(self::MOVED, $client->config()->origin);

        // An origin read from the cache passes the same shape check as one from the fallback host.
        foreach (['http://evil.example.com', 'https://203.0.113.10', 'https://api2.example.com/x', 42] as $origin) {
            $store = new ArrayOriginStore($clock);
            $probe = $this->client(new RoutedTransport(), $clock, $store);
            $probe->config();
            $store->values[array_key_first($store->values) ?? $this->storeKey($store, $clock)] = [
                (string) json_encode(['origin' => $origin, 'until' => (int) $clock->time + 3600, 'paused' => null]),
                $clock->time + 3600,
            ];
            self::assertSame(self::PRIMARY, $this->client(new RoutedTransport(), $clock, $store)->config()->origin);
        }
    }

    public function testACallMadeWhileTheLookupRunsDoesNotStartAnotherOne(): void
    {
        $nested = null;
        $transport = new RoutedTransport();
        $client = $this->client($transport);
        $transport
            ->on(self::PRIMARY, self::unreachable())
            ->on(self::FALLBACK, static function (HttpRequest $request) use ($client, &$nested): HttpResponse {
                // What a coroutine runtime does: another call of the same client runs while the
                // lookup waits for its answer.
                try {
                    $client->project->get();
                } catch (ConnectionException $e) {
                    $nested = $e;
                }

                return self::brand(self::MOVED)($request);
            })
            ->on(self::MOVED, self::ok(self::PROJECT_DATA));

        $client->project->get();

        self::assertInstanceOf(ConnectionException::class, $nested);
        self::assertCount(1, $transport->to(self::FALLBACK));
        self::assertCount(1, $transport->to(self::MOVED));
    }

    public function testClientConfigNormalizesTheFallbackBaseUrlLikeTheBaseUrl(): void
    {
        $config = static fn(?string $fallback): ClientConfig => new ClientConfig(self::PRIMARY, ClientFactory::PROJECT, new Secret('k'), fallbackBaseUrl: $fallback);

        self::assertSame(self::FALLBACK, $config('https://API.example.org/api/v4/')->fallbackOrigin);
        self::assertNull($config(null)->fallbackOrigin);
        self::assertNull($config(' ')->fallbackOrigin);
        self::assertSame(self::FALLBACK, $config(self::FALLBACK)->withCredentials(ClientFactory::PROJECT, new Secret('other'))->fallbackOrigin);

        $this->expectException(\Enigmoss\Exception\ConfigurationException::class);
        $this->expectExceptionMessage('fallbackBaseUrl must use https');
        $config('http://api.example.org');
    }

    /**
     * @param list<ObserverInterface> $observers
     */
    private function client(RoutedTransport $transport, ?FakeClock $clock = null, ?OriginStoreInterface $store = null, array $observers = []): EnigmossClient
    {
        $clock ??= new FakeClock();

        return new EnigmossClient(
            new ClientConfig(
                baseUrl: self::PRIMARY,
                projectId: ClientFactory::PROJECT,
                paymentKey: new Secret(ClientFactory::PAYMENT_SECRET),
                payoutKey: new Secret(ClientFactory::PAYOUT_SECRET),
                fallbackBaseUrl: self::FALLBACK,
                originStore: $store,
            ),
            $transport,
            $clock,
            $clock,
            $observers,
        );
    }

    private function storeKey(ArrayOriginStore $store, FakeClock $clock): string
    {
        // Make the SDK write once to learn the key it uses for this configuration.
        $transport = (new RoutedTransport())->on(self::PRIMARY, self::unreachable())->on(self::FALLBACK, self::unreachable());

        try {
            $this->client($transport, $clock, $store)->project->get();
        } catch (ConnectionException) {
        }
        $key = array_key_first($store->values);
        self::assertIsString($key);

        return $key;
    }

    /**
     * What CurlTransport throws when the API host could not be reached at all.
     */
    private static function unreachable(string $message = 'curl error 6: Could not resolve host'): ConnectionException
    {
        return new ConnectionException($message, null, true);
    }

    private static function ok(mixed $data, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, ['Content-Type' => 'application/json', 'X-Request-Id' => 'req-ok'], (string) json_encode(['data' => $data], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function error(int $status, string $type, string $code, array $details = []): HttpResponse
    {
        $error = ['type' => $type, 'code' => $code, 'message' => 'Fallback says no.', 'retryable' => false] + ($details === [] ? [] : ['details' => $details]);

        return new HttpResponse($status, ['Content-Type' => 'application/json'], (string) json_encode(['error' => $error, 'meta' => ['request_id' => 'req-fallback']]));
    }

    /**
     * The platform brand as the fallback host answers it: signed for the very request.
     *
     * @return \Closure(HttpRequest): HttpResponse
     */
    private static function brand(
        ?string $origin,
        int $refreshAfter = 86400,
        string $key = ClientFactory::PAYMENT_SECRET,
        int $timestampShift = 0,
        ?string $requestSignature = null,
    ): \Closure {
        $data = Fixtures::platformBrand(['refresh_after' => $refreshAfter]);
        if ($origin === null) {
            unset($data['api']);
        } else {
            $data['api'] = ['origin' => $origin];
        }

        return self::signed((string) json_encode(['data' => $data, 'meta' => ['request_id' => 'req-brand']], JSON_UNESCAPED_SLASHES), $key, $timestampShift, $requestSignature);
    }

    /**
     * @return \Closure(HttpRequest): HttpResponse
     */
    private static function signed(string $body, string $key = ClientFactory::PAYMENT_SECRET, int $timestampShift = 0, ?string $requestSignature = null): \Closure
    {
        return static function (HttpRequest $request) use ($body, $key, $timestampShift, $requestSignature): HttpResponse {
            $timestamp = (string) ((int) $request->headers['X-Timestamp'] + $timestampShift);
            $signature = hash_hmac('sha256', $timestamp . "\n" . ($requestSignature ?? $request->headers['X-Signature']) . "\n" . $body, $key);

            return new HttpResponse(200, [
                'Content-Type' => 'application/json',
                'X-Request-Id' => 'req-brand',
                'X-Response-Timestamp' => $timestamp,
                'X-Response-Signature' => strtoupper($signature),
            ], $body);
        };
    }
}
