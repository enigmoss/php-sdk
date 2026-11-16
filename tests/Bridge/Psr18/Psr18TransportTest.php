<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Bridge\Psr18;

use Enigmoss\Bridge\Psr18\Psr18Transport;
use Enigmoss\Exception\ConnectionException;
use Enigmoss\Exception\SendPhase;
use Enigmoss\Exception\TransportException;
use Enigmoss\Http\HttpRequest;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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
        $client = new class ($failure) implements ClientInterface {
            public function __construct(private readonly ClientExceptionInterface $failure) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw $this->failure;
            }
        };
        $factory = new HttpFactory();
        $transport = new Psr18Transport($client, $factory, $factory);

        try {
            $transport->send(new HttpRequest('POST', 'https://api.example.com/api/v4/payouts', [], '{}', 30.0, 10.0));
            self::fail('Expected TransportException.');
        } catch (TransportException $e) {
            self::assertNotInstanceOf(ConnectionException::class, $e);
            self::assertSame(SendPhase::Unknown, $e->sendPhase);
            self::assertTrue($e->outcomeUnknown());
            self::assertSame($failure, $e->getPrevious());
        }
    }
}
