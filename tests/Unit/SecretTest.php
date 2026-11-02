<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Unit;

use Enigmoss\ClientConfig;
use Enigmoss\Exception\InvalidArgumentException;
use Enigmoss\Secret;
use PHPUnit\Framework\TestCase;

final class SecretTest extends TestCase
{
    private const VALUE = 'super-secret-key-value';

    public function testRevealsTheValueForSigning(): void
    {
        self::assertSame(self::VALUE, (new Secret(self::VALUE))->reveal());
    }

    public function testValueDoesNotLeakThroughDumps(): void
    {
        $secret = new Secret(self::VALUE);
        $config = new ClientConfig('https://api.example.com', '3f2b8c1e-5d4a-4e7b-9c6f-1a2b3c4d5e6f', $secret);

        foreach ([$secret, $config] as $object) {
            self::assertStringNotContainsString(self::VALUE, print_r($object, true));
            self::assertStringNotContainsString(self::VALUE, var_export($object, true));
            ob_start();
            var_dump($object);
            self::assertStringNotContainsString(self::VALUE, (string) ob_get_clean());
        }
        self::assertSame('********', (string) $secret);
    }

    public function testCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);
        serialize(new Secret(self::VALUE));
    }

    public function testEmptySecretIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Secret('');
    }
}
