<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Unit\Http;

use Enigmoss\Exception\ConnectionException;
use Enigmoss\Exception\SendPhase;
use Enigmoss\Exception\TransportException;
use Enigmoss\Http\CurlTransport;
use Enigmoss\Http\HttpRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Which curl failures prove that the API host itself could not be reached - the only failures
 * that may start the API-origin fallback.
 */
#[RequiresPhpExtension('curl')]
final class CurlTransportTest extends TestCase
{
    private const PROXY_VARIABLES = ['http_proxy', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY', 'no_proxy', 'NO_PROXY'];

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (self::PROXY_VARIABLES as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testARefusedDirectConnectionProvesTheHostIsUnreachable(): void
    {
        $failure = $this->failure(new CurlTransport(), self::closedPort());

        self::assertSame(SendPhase::NotSent, $failure->sendPhase);
        self::assertTrue($failure->hostUnreachable);
        self::assertFalse($failure->outcomeUnknown());
    }

    public function testBehindAConfiguredProxyARefusedConnectionProvesNothing(): void
    {
        $failure = $this->failure(new CurlTransport(proxy: 'http://127.0.0.1:' . self::closedPort()), self::closedPort());

        // Still "not sent" for the retries, but it may be the proxy that is down.
        self::assertSame(SendPhase::NotSent, $failure->sendPhase);
        self::assertFalse($failure->hostUnreachable);
    }

    public function testBehindAProxyFromTheEnvironmentARefusedConnectionProvesNothing(): void
    {
        putenv('http_proxy=http://127.0.0.1:' . self::closedPort());

        $failure = $this->failure(new CurlTransport(), self::closedPort());

        self::assertSame(SendPhase::NotSent, $failure->sendPhase);
        self::assertFalse($failure->hostUnreachable);
    }

    /**
     * Measured with libcurl 8.23.0 (OpenSSL) and 8.7.1 (SecureTransport); the wording of older
     * versions is taken from their sources.
     *
     * @return iterable<string, array{string, int, float, float, float, bool, bool}>
     */
    public static function timeouts(): iterable
    {
        // error text, request size, connect time, appconnect time, pretransfer time, https, not sent?

        // The connection was never made.
        yield '8.23: TLS handshake never finishes, pretransfer is the moment of the failure' => ['Connection timed out after 2001 milliseconds', 0, 0.0026, 0.0, 2.0021, true, true];
        yield '8.23: TCP connect never finishes, http' => ['Connection timed out after 2001 milliseconds', 0, 0.0, 0.0, 2.0019, false, true];
        yield '8.7: TLS handshake never finishes' => ['SSL connection timeout', 0, 0.000341, 0.0, 0.0, true, true];
        yield 'DNS lookup never finishes' => ['Resolving timed out after 2000 milliseconds', 0, 0.0, 0.0, 2.0005, true, true];
        yield '7.81 wording' => ['Connection timeout after 2001 ms', 0, 0.0, 0.0, 0.0, true, true];
        yield 'timeout of one address' => ['Failed to connect to api.example.com port 443 after 2002 ms: Timeout was reached', 0, 0.0, 0.0, 2.002, true, true];
        yield 'unknown wording, but the transfer never got ready' => ['Timeout was reached', 0, 0.0, 0.0, 0.0, true, true];

        // The request was sent, or may have been.
        yield '8.23: sent on a fresh https connection, no answer' => ['Operation timed out after 3002 milliseconds with 0 bytes received', 83, 0.000301, 0.011094, 0.011128, true, false];
        yield '8.7: sent on a fresh https connection, request size reported as 0' => ['Operation timed out after 3004 milliseconds with 0 bytes received', 0, 0.000621, 0.082884, 0.082935, true, false];
        yield '8.7: sent on a fresh http connection, request size reported as 0' => ['Operation timed out after 3001 milliseconds with 0 bytes received', 0, 0.000846, 0.0, 0.001198, false, false];
        yield '8.7: sent on a REUSED https connection: size 0 and connect times 0' => ['Operation timed out after 3003 milliseconds with 0 bytes received', 0, 0.0, 0.0, 0.000024, true, false];
        yield '8.7: sent on a REUSED http connection: size 0 and connect time 0' => ['Operation timed out after 3002 milliseconds with 0 bytes received', 0, 0.0, 0.0, 0.000115, false, false];
        yield '8.23: sent on a reused https connection' => ['Operation timed out after 3001 milliseconds with 0 bytes received', 83, 0.0, 0.0, 0.000021, true, false];
        yield 'unknown wording after the transfer got ready' => ['Timeout was reached', 0, 0.0, 0.0, 0.5, true, false];
        yield 'the signs disagree: connect wording, but the TLS handshake finished' => ['Connection timed out after 2001 milliseconds', 0, 0.001, 0.05, 2.0, true, false];
        yield 'the signs disagree: connect wording, but request bytes were counted' => ['Connection timed out after 2001 milliseconds', 120, 0.0, 0.0, 2.0, true, false];
    }

    #[DataProvider('timeouts')]
    public function testATimeoutCountsAsNotSentOnlyWhenEverySignSaysSo(
        string $error,
        int $requestSize,
        float $connectTime,
        float $appConnectTime,
        float $preTransferTime,
        bool $tls,
        bool $notSent,
    ): void {
        self::assertSame($notSent, CurlTransport::timedOutBeforeSending($error, $requestSize, $connectTime, $appConnectTime, $preTransferTime, $tls));
    }

    public function testAStalledTlsHandshakeIsAConnectTimeoutAndProvesTheHostIsUnreachable(): void
    {
        // The port accepts TCP connections and never says a word: what a blocked host looks like.
        $silent = self::silentListener();

        try {
            (new CurlTransport())->send(new HttpRequest('POST', 'https://127.0.0.1:' . self::port($silent) . '/api/v4/payouts', [], '{}', 5.0, 1.0));
            self::fail('Expected ConnectionException.');
        } catch (TransportException $e) {
            self::assertInstanceOf(ConnectionException::class, $e, $e->getMessage());
            self::assertSame(SendPhase::NotSent, $e->sendPhase);
            self::assertTrue($e->hostUnreachable);
            self::assertFalse($e->outcomeUnknown());
        } finally {
            fclose($silent);
        }
    }

    public function testATimeoutAfterTheRequestWasSentIsNotAConnectTimeout(): void
    {
        // Plain http: the TCP connection is made, the request is written, no answer ever comes.
        $silent = self::silentListener();

        try {
            (new CurlTransport())->send(new HttpRequest('POST', 'http://127.0.0.1:' . self::port($silent) . '/api/v4/payouts', [], '{}', 1.0, 1.0));
            self::fail('Expected TransportException.');
        } catch (TransportException $e) {
            self::assertNotInstanceOf(ConnectionException::class, $e, $e->getMessage());
            self::assertSame(SendPhase::Sent, $e->sendPhase);
            self::assertTrue($e->timedOut);
            self::assertFalse($e->hostUnreachable);
            self::assertTrue($e->outcomeUnknown());
        } finally {
            fclose($silent);
        }
    }

    private function failure(CurlTransport $transport, int $port): ConnectionException
    {
        try {
            $transport->send(new HttpRequest('GET', 'http://127.0.0.1:' . $port . '/api/v4/project', [], null, 5.0, 2.0));
        } catch (ConnectionException $e) {
            return $e;
        }

        self::fail('Expected ConnectionException.');
    }

    /**
     * A local port nothing listens on: bound once to reserve the number, then closed.
     */
    private static function closedPort(): int
    {
        $server = self::silentListener();
        $port = self::port($server);
        fclose($server);

        return $port;
    }

    /**
     * A listening socket that is never accepted: the TCP connection is made by the kernel,
     * and nothing answers on it.
     *
     * @return resource
     */
    private static function silentListener()
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($server, (string) $error);

        return $server;
    }

    /**
     * @param resource $server
     */
    private static function port($server): int
    {
        $name = (string) stream_socket_get_name($server, false);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
