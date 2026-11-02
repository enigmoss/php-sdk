<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Bridge;

use Enigmoss\Auth\KeyScope;
use Enigmoss\Auth\RequestSigner;
use Enigmoss\Bridge\Psr18\Psr18Transport;
use Enigmoss\Bridge\Symfony\Http\SymfonyHttpClientTransport;
use Enigmoss\ClientConfig;
use Enigmoss\EnigmossClient;
use Enigmoss\Http\CurlTransport;
use Enigmoss\Http\HttpRequest;
use Enigmoss\Http\TransportInterface;
use Enigmoss\Secret;
use Enigmoss\Tests\Support\EchoServer;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Every transport must put on the wire exactly the bytes that were signed. The echo server
 * re-checks the signature independently of the SDK code.
 */
final class WireConformanceTest extends TestCase
{
    private const PROJECT = '3f2b8c1e-5d4a-4e7b-9c6f-1a2b3c4d5e6f';

    /**
     * @return iterable<string, array{\Closure(): TransportInterface}>
     */
    public static function transports(): iterable
    {
        yield 'curl' => [static fn(): TransportInterface => new CurlTransport()];
        yield 'psr18 guzzle' => [static function (): TransportInterface {
            $factory = new HttpFactory();

            return new Psr18Transport(new Guzzle(['allow_redirects' => false, 'http_errors' => false, 'timeout' => 10]), $factory, $factory);
        }];
        yield 'symfony http client' => [static fn(): TransportInterface => new SymfonyHttpClientTransport(HttpClient::create())];
    }

    /**
     * @param \Closure(): TransportInterface $transport
     */
    #[DataProvider('transports')]
    public function testQueryWithReservedCharactersIsSentAsSigned(\Closure $transport): void
    {
        $echo = $this->client($transport())->request('GET', '/events', query: [
            'cursor' => 'eyJpZCI6IjAxOTcifQ+/==',
            'created_from' => '2026-10-01T00:00:00Z',
            'event' => 'payment.paid',
        ]);

        self::assertIsArray($echo->data);
        self::assertTrue($echo->data['signature_valid']);
        self::assertSame('/api/v4/events?cursor=eyJpZCI6IjAxOTcifQ%2B%2F%3D%3D&created_from=2026-10-01T00%3A00%3A00Z&event=payment.paid', $echo->data['uri']);
    }

    /**
     * @param \Closure(): TransportInterface $transport
     */
    #[DataProvider('transports')]
    public function testBodylessPostSendsSignedEmptyObject(\Closure $transport): void
    {
        $echo = $this->client($transport())->request('POST', '/payments/7c9e6679-7425-40de-944b-e07fc1f90ae7/cancel', idempotent: true);

        self::assertIsArray($echo->data);
        self::assertTrue($echo->data['signature_valid']);
        self::assertSame('{}', $echo->data['body']);
        self::assertSame('application/json', $echo->data['content_type']);
        self::assertNotNull($echo->data['idempotency_key']);
    }

    /**
     * @param \Closure(): TransportInterface $transport
     */
    #[DataProvider('transports')]
    public function testPatchWithoutQueryHasNoQuestionMark(\Closure $transport): void
    {
        $echo = $this->client($transport())->request('PATCH', '/payment-methods/USDT/tron', ['discount_percent' => 5]);

        self::assertIsArray($echo->data);
        self::assertTrue($echo->data['signature_valid']);
        self::assertSame('/api/v4/payment-methods/USDT/tron', $echo->data['uri']);
        self::assertSame('PATCH', $echo->data['method']);
    }

    /**
     * The Unicode + CRLF test vector: these bytes must survive every transport.
     *
     * @param \Closure(): TransportInterface $transport
     */
    #[DataProvider('transports')]
    public function testRawUnicodeCrlfBodyIsSentByteForByte(\Closure $transport): void
    {
        $origin = EchoServer::start();
        $body = "{\r\n  \"description\": \"Оплата заказа №42 — café\"\r\n}";
        $timestamp = time();
        $request = new HttpRequest('POST', $origin . '/api/v4/payouts', [
            'Content-Type' => 'application/json',
            'X-Project-Uuid' => self::PROJECT,
            'X-Timestamp' => (string) $timestamp,
            'X-Signature' => RequestSigner::signRequest(new Secret('test-payout-secret'), $timestamp, self::PROJECT, 'POST', '/api/v4/payouts', $body),
        ], $body, 10, 5);

        $response = $transport()->send($request);
        $echo = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($echo);
        self::assertIsArray($echo['data']);
        self::assertTrue($echo['data']['signature_valid']);
        self::assertSame($body, $echo['data']['body']);
    }

    /**
     * @param \Closure(): TransportInterface $transport
     */
    #[DataProvider('transports')]
    public function testRedirectsAreNeverFollowed(\Closure $transport): void
    {
        $origin = EchoServer::start();
        $response = $transport()->send(new HttpRequest('GET', $origin . '/api/v4/redirect', [], null, 10, 5));

        self::assertSame(302, $response->statusCode);
    }

    /**
     * @param \Closure(): TransportInterface $transport
     */
    #[DataProvider('transports')]
    public function testUserAgentStartsWithTheSdkToken(\Closure $transport): void
    {
        $echo = $this->client($transport())->request('GET', '/project', scope: KeyScope::Any);

        self::assertIsArray($echo->data);
        self::assertIsString($echo->data['user_agent']);
        self::assertMatchesRegularExpression('#^enigmoss-php-sdk/\d+\.\d+\.\d+#', $echo->data['user_agent']);
    }

    private function client(TransportInterface $transport): EnigmossClient
    {
        return new EnigmossClient(
            new ClientConfig(
                EchoServer::start(),
                self::PROJECT,
                new Secret('test-payment-secret'),
                new Secret('test-payout-secret'),
                timeout: 10,
                maxRetries: 0,
                allowInsecureHttp: true,
            ),
            $transport,
        );
    }
}
