<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Unit;

use Enigmoss\ClientConfig;
use Enigmoss\Exception\ConfigurationException;
use Enigmoss\Secret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientConfigTest extends TestCase
{
    private const PROJECT = '3F2B8C1E-5D4A-4E7B-9C6F-1A2B3C4D5E6F';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedBaseUrls(): iterable
    {
        yield 'host' => ['https://api.example.com', 'https://api.example.com'];
        yield 'trailing slash' => ['https://api.example.com/', 'https://api.example.com'];
        yield 'with api prefix' => ['https://api.example.com/api/v4', 'https://api.example.com'];
        yield 'with api prefix and slash' => ['https://API.example.com/api/v4/', 'https://api.example.com'];
        yield 'port' => ['https://api.example.com:8443', 'https://api.example.com:8443'];
    }

    #[DataProvider('acceptedBaseUrls')]
    public function testBaseUrlIsNormalizedToOrigin(string $baseUrl, string $origin): void
    {
        self::assertSame($origin, (new ClientConfig($baseUrl, self::PROJECT, new Secret('k')))->origin);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedBaseUrls(): iterable
    {
        yield 'relative' => ['api.example.com'];
        yield 'plain http' => ['http://api.example.com'];
        yield 'other path' => ['https://gateway.example.com/project'];
        yield 'query' => ['https://api.example.com?x=1'];
        yield 'credentials' => ['https://user:pass@api.example.com'];
    }

    #[DataProvider('rejectedBaseUrls')]
    public function testInvalidBaseUrlIsRejected(string $baseUrl): void
    {
        $this->expectException(ConfigurationException::class);
        new ClientConfig($baseUrl, self::PROJECT, new Secret('k'));
    }

    public function testHttpIsAllowedOnlyExplicitly(): void
    {
        $config = new ClientConfig('http://localhost:8083', self::PROJECT, new Secret('k'), allowInsecureHttp: true);

        self::assertSame('http://localhost:8083', $config->origin);
    }

    public function testProjectIdIsLowerCased(): void
    {
        self::assertSame(strtolower(self::PROJECT), (new ClientConfig('https://api.example.com', self::PROJECT, new Secret('k')))->projectId);
    }

    public function testProjectIdMustBeUuid(): void
    {
        $this->expectException(ConfigurationException::class);
        new ClientConfig('https://api.example.com', '12345', new Secret('k'));
    }

    public function testAtLeastOneKeyIsRequired(): void
    {
        $this->expectException(ConfigurationException::class);
        new ClientConfig('https://api.example.com', self::PROJECT);
    }
}
