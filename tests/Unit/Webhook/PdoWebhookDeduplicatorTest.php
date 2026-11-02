<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Unit\Webhook;

use Enigmoss\Webhook\PdoWebhookDeduplicator;
use PHPUnit\Framework\TestCase;

final class PdoWebhookDeduplicatorTest extends TestCase
{
    public function testClaimIsExclusiveUntilReleased(): void
    {
        if (!\in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not installed.');
        }
        $pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE enigmoss_webhook_events (event_id VARCHAR(64) PRIMARY KEY, claimed_at TIMESTAMP NOT NULL)');
        $first = new PdoWebhookDeduplicator($pdo);
        $second = new PdoWebhookDeduplicator($pdo);

        self::assertTrue($first->claim('evt-1'));
        self::assertFalse($second->claim('evt-1'));
        self::assertTrue($second->claim('evt-2'));

        $first->release('evt-1');
        self::assertTrue($second->claim('evt-1'));
    }
}
