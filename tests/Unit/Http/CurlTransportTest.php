<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Unit\Http;

use Enigmoss\Exception\ConnectionException;
use Enigmoss\Exception\SendPhase;
use Enigmoss\Http\CurlTransport;
use Enigmoss\Http\HttpRequest;
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
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($server, (string) $error);
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
