<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Bridge\Psr16;

use Enigmoss\Bridge\Psr16\Psr16OriginStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class Psr16OriginStoreTest extends TestCase
{
    public function testValuesGoThroughThePsr16Cache(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $store = new Psr16OriginStore($cache);

        self::assertNull($store->get('enigmoss_origin_0123456789abcdef0123456789abcdef'));

        $store->set('enigmoss_origin_0123456789abcdef0123456789abcdef', '{"origin":"https://api2.example.com"}', 3600);

        self::assertSame('{"origin":"https://api2.example.com"}', $store->get('enigmoss_origin_0123456789abcdef0123456789abcdef'));
        self::assertSame('{"origin":"https://api2.example.com"}', $cache->get('enigmoss_origin_0123456789abcdef0123456789abcdef'));

        // Something else under the key is not an origin record.
        $cache->set('enigmoss_origin_0123456789abcdef0123456789abcdef', ['not' => 'a string']);
        self::assertNull($store->get('enigmoss_origin_0123456789abcdef0123456789abcdef'));
    }
}
