<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Unit\Internal;

use Enigmoss\Internal\ApiOrigin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiOriginTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function accepted(): iterable
    {
        yield 'plain' => ['https://api2.example.com', 'https://api2.example.com'];
        yield 'upper case' => ['HTTPS://API2.Example.COM', 'https://api2.example.com'];
        yield 'default port' => ['https://api2.example.com:443', 'https://api2.example.com'];
        yield 'two labels' => ['https://example.org', 'https://example.org'];
        yield 'punycode' => ['https://xn--80akhbyknj4f.xn--p1ai', 'https://xn--80akhbyknj4f.xn--p1ai'];
        yield 'digits and hyphens' => ['https://api-2.eu1.example.com', 'https://api-2.eu1.example.com'];
    }

    #[DataProvider('accepted')]
    public function testAcceptsAPlainHttpsOrigin(string $value, string $origin): void
    {
        self::assertSame($origin, ApiOrigin::fromResponse($value, false));
        self::assertSame($origin, ApiOrigin::fromResponse($value, true));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function rejected(): iterable
    {
        yield 'null' => [null];
        yield 'not a string' => [['https://api2.example.com']];
        yield 'empty' => [''];
        yield 'http' => ['http://api2.example.com'];
        yield 'no scheme' => ['api2.example.com'];
        yield 'other scheme' => ['ftp://api2.example.com'];
        yield 'other port' => ['https://api2.example.com:8443'];
        yield 'empty port' => ['https://api2.example.com:'];
        yield 'userinfo' => ['https://user@api2.example.com'];
        yield 'password' => ['https://user:pass@api2.example.com'];
        yield 'trailing slash' => ['https://api2.example.com/'];
        yield 'path' => ['https://api2.example.com/api/v4'];
        yield 'query' => ['https://api2.example.com?x=1'];
        yield 'fragment' => ['https://api2.example.com#x'];
        yield 'ipv4' => ['https://203.0.113.10'];
        yield 'ipv4 with port' => ['https://203.0.113.10:443'];
        yield 'ipv6' => ['https://[2001:db8::1]'];
        yield 'hex ip' => ['https://0x7f.0.0.1'];
        yield 'localhost' => ['https://localhost'];
        yield 'single label' => ['https://intranet'];
        yield 'dot local' => ['https://printer.local'];
        yield 'dot localhost' => ['https://api.localhost'];
        yield 'trailing dot' => ['https://api2.example.com.'];
        yield 'leading space' => [' https://api2.example.com'];
        yield 'trailing newline' => ["https://api2.example.com\n"];
        yield 'inner space' => ['https://api2.exa mple.com'];
        yield 'underscore' => ['https://api_2.example.com'];
        yield 'label starts with hyphen' => ['https://-api.example.com'];
        yield 'unicode host' => ['https://пример.example.com'];
        yield 'backslash' => ['https://api2.example.com\\@evil.example.org'];
        yield 'too long host' => ['https://' . str_repeat('a.', 130) . 'example.com'];
    }

    #[DataProvider('rejected')]
    public function testRejectsEverythingElse(mixed $value): void
    {
        self::assertNull(ApiOrigin::fromResponse($value, false));
        self::assertNull(ApiOrigin::fromResponse($value, true));
    }

    public function testPlainHttpIsForLoopbackUnderTheInsecureFlagOnly(): void
    {
        self::assertNull(ApiOrigin::fromResponse('http://localhost:8080', false));
        self::assertSame('http://localhost:8080', ApiOrigin::fromResponse('http://localhost:8080', true));
        self::assertSame('http://127.0.0.1', ApiOrigin::fromResponse('http://127.0.0.1:80', true));
        self::assertSame('http://[::1]:8000', ApiOrigin::fromResponse('http://[::1]:8000', true));
        self::assertNull(ApiOrigin::fromResponse('http://api2.example.com', true));
        self::assertNull(ApiOrigin::fromResponse('http://localhost:8080/', true));
        self::assertNull(ApiOrigin::fromResponse('http://192.168.1.10:8080', true));
    }

    public function testNormalizeDropsCaseAndTheDefaultPort(): void
    {
        self::assertSame('https://api.example.com', ApiOrigin::normalize('https://API.example.com:443'));
        self::assertSame('https://api.example.com:8443', ApiOrigin::normalize('https://api.example.com:8443'));
        self::assertSame('http://localhost', ApiOrigin::normalize('http://localhost:80'));
    }
}
