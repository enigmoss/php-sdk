<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Bridge\Psr18;

use Enigmoss\Bridge\Psr18\Psr18Transport;
use Enigmoss\ClientConfig;
use Enigmoss\EnigmossClient;
use Enigmoss\Exception\ConnectionException;
use Enigmoss\Exception\SendPhase;
use Enigmoss\Exception\TransportException;
use Enigmoss\Http\HttpRequest;
use Enigmoss\Secret;
use Enigmoss\Tests\Support\FailingPsr18Client;
use Enigmoss\Tests\Support\FakeClock;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class Psr18TransportTest extends TestCase
{
    /**
     * @return iterable<string, array{ClientExceptionInterface}>
     */
    public static function failures(): iterable
    {
        $request = new Request('POST', 'https://api.example.com/api/v4/payouts');

        // Guzzle throws its RequestException (a PSR-18 RequestExceptionInterface) when a transfer
        // breaks after the request was written, e.g. curl error 56.
        yield 'request exception' => [new class ('cURL error 56: Recv failure', $request) extends \RuntimeException implements RequestExceptionInterface {
            public function __construct(string $message, private readonly RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        }];
        yield 'network exception' => [new class ('cURL error 28: Operation timed out', $request) extends \RuntimeException implements NetworkExceptionInterface {
            public function __construct(string $message, private readonly RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        }];
        yield 'any client exception' => [new class ('Something else') extends \RuntimeException implements ClientExceptionInterface {}];
    }

    #[DataProvider('failures')]
    public function testNoPsr18FailureIsReportedAsNotSent(ClientExceptionInterface $failure): void
    {
        $transport = $this->transport($failure);

        try {
            $transport->send(new HttpRequest('POST', 'https://api.example.com/api/v4/payouts', [], '{}', 30.0, 10.0));
            self::fail('Expected TransportException.');
        } catch (TransportException $e) {
            self::assertNotInstanceOf(ConnectionException::class, $e);
            self::assertSame(SendPhase::Unknown, $e->sendPhase);
            self::assertTrue($e->outcomeUnknown());
            self::assertSame($failure, $e->getPrevious());
            self::assertFalse($e->hostUnreachable);
        }
    }

    /**
     * PSR-18 cannot prove that the API host was not reached, so the fallback host is never
     * asked through this bridge, whatever the client reports.
     */
    #[DataProvider('failures')]
    public function testTheApiOriginFallbackNeverStartsThroughPsr18(ClientExceptionInterface $failure): void
    {
        $psr18 = new FailingPsr18Client($failure);
        $factory = new HttpFactory();
        $transport = new Psr18Transport($psr18, $factory, $factory);
        $clock = new FakeClock();
        $client = new EnigmossClient(
            new ClientConfig(
                'https://api.example.com',
                '3f2b8c1e-5d4a-4e7b-9c6f-1a2b3c4d5e6f',
                new Secret('test-payment-secret'),
                fallbackBaseUrl: 'https://api.example.org',
            ),
            $transport,
            $clock,
            $clock,
        );

        try {
            $client->project->get();
            self::fail('Expected TransportException.');
        } catch (TransportException $e) {
            self::assertSame($failure, $e->getPrevious());
        }

        // Three attempts on the configured host (a read is retried), none on the fallback host.
        self::assertSame(
            ['api.example.com', 'api.example.com', 'api.example.com'],
            array_map(static fn(RequestInterface $r): string => $r->getUri()->getHost(), $psr18->requests),
        );
        self::assertSame('https://api.example.com', $client->config()->origin);
    }

    private function transport(ClientExceptionInterface $failure): Psr18Transport
    {
        $factory = new HttpFactory();

        return new Psr18Transport(new FailingPsr18Client($failure), $factory, $factory);
    }
}
