<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Unit\Internal;

use Enigmoss\Auth\KeyScope;
use Enigmoss\Internal\Endpoint;
use Enigmoss\Internal\IdempotencyKeyResolver;
use Enigmoss\Internal\IdempotencyMode;
use PHPUnit\Framework\TestCase;

final class IdempotencyKeyResolverTest extends TestCase
{
    public function testCreateKeyIsDerivedFromPathAndOrderId(): void
    {
        $endpoint = Endpoint::create('/payouts', KeyScope::Payout, IdempotencyMode::Required);

        $first = IdempotencyKeyResolver::resolve($endpoint, '/api/v4/payouts', 'order-1', null);
        $again = IdempotencyKeyResolver::resolve($endpoint, '/api/v4/payouts', 'order-1', null);
        $other = IdempotencyKeyResolver::resolve($endpoint, '/api/v4/transfers', 'order-1', null);

        self::assertSame($first, $again);
        self::assertNotSame($first, $other);
        self::assertNotNull($first);
        self::assertSame(64, \strlen($first));
        self::assertMatchesRegularExpression('/^sdk1-[0-9a-f]{59}$/', $first);
    }

    public function testExplicitKeyWins(): void
    {
        $endpoint = Endpoint::create('/payouts', KeyScope::Payout, IdempotencyMode::Required);

        self::assertSame('my-key', IdempotencyKeyResolver::resolve($endpoint, '/api/v4/payouts', 'order-1', 'my-key'));
    }

    public function testActionsGetRandomUuid(): void
    {
        $endpoint = Endpoint::action('/payments/{id}/mark-paid', KeyScope::Payment);

        $first = IdempotencyKeyResolver::resolve($endpoint, '/api/v4/payments/x/mark-paid', null, null);
        $second = IdempotencyKeyResolver::resolve($endpoint, '/api/v4/payments/x/mark-paid', null, null);

        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $first);
    }

    public function testEndpointsWithoutIdempotencyGetNoKey(): void
    {
        self::assertNull(IdempotencyKeyResolver::resolve(Endpoint::get('/payments', KeyScope::Payment), '/api/v4/payments', null, 'ignored'));
    }
}
