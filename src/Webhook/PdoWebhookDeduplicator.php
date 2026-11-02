<?php

declare(strict_types=1);

namespace Enigmoss\Webhook;

/**
 * Atomic webhook deduplication in your own database (PostgreSQL, MySQL/MariaDB, SQLite).
 *
 *     CREATE TABLE enigmoss_webhook_events (
 *         event_id   VARCHAR(64) PRIMARY KEY,
 *         claimed_at TIMESTAMP   NOT NULL
 *     );
 *
 * Delete rows older than a few days with a periodic job: the API stops redelivering after two days.
 */
final class PdoWebhookDeduplicator implements WebhookDeduplicatorInterface
{
    private readonly string $table;

    public function __construct(private readonly \PDO $pdo, string $table = 'enigmoss_webhook_events')
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]{0,63}$/', $table) !== 1) {
            throw new \Enigmoss\Exception\InvalidArgumentException('Invalid table name.');
        }
        $this->table = $table;
    }

    public function claim(string $eventId): bool
    {
        $insert = match ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'mysql' => 'INSERT IGNORE INTO %s (event_id, claimed_at) VALUES (?, ?)',
            default => 'INSERT INTO %s (event_id, claimed_at) VALUES (?, ?) ON CONFLICT (event_id) DO NOTHING',
        };
        $statement = $this->pdo->prepare(\sprintf($insert, $this->table));
        $statement->execute([$eventId, gmdate('Y-m-d H:i:s')]);

        return $statement->rowCount() === 1;
    }

    public function release(string $eventId): void
    {
        $this->pdo->prepare(\sprintf('DELETE FROM %s WHERE event_id = ?', $this->table))->execute([$eventId]);
    }
}
