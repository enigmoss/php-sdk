<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Unit\Internal;

use Enigmoss\Internal\UriBuilder;
use PHPUnit\Framework\TestCase;

final class UriBuilderTest extends TestCase
{
    public function testNoQueryProducesNoQuestionMark(): void
    {
        $uri = UriBuilder::build('https://api.example.com', '/api/v4/payments', ['status' => null]);

        self::assertSame('/api/v4/payments', $uri['requestUri']);
        self::assertSame('https://api.example.com/api/v4/payments', $uri['url']);
    }

    public function testQueryIsRfc3986EncodedInOrder(): void
    {
        $uri = UriBuilder::build('https://api.example.com', '/api/v4/events', [
            'cursor' => 'eyJpZCI6+/=',
            'created_from' => '2026-10-01T00:00:00Z',
            'limit' => 50,
            'flag' => true,
        ]);

        self::assertSame('/api/v4/events?cursor=eyJpZCI6%2B%2F%3D&created_from=2026-10-01T00%3A00%3A00Z&limit=50&flag=true', $uri['requestUri']);
    }
}
