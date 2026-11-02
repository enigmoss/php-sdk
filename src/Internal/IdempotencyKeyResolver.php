<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

/**
 * Chooses the Idempotency-Key of a call.
 *
 * - An explicit key from RequestOptions always wins.
 * - Create endpoints derive the key from the order_id: "sdk1-" + 59 hex chars of
 *   sha256(path "\n" order_id). A repeated create (a retried job, a double click) gets the
 *   stored response instead of a 409 conflict. The path is part of the hash because keys are
 *   shared by all endpoints of a project.
 * - Other idempotent actions get a random UUIDv4 per call, reused by the SDK's own retries.
 *
 * @internal
 */
final class IdempotencyKeyResolver
{
    public const DERIVED_PREFIX = 'sdk1-';

    public static function resolve(Endpoint $endpoint, string $requestUri, ?string $orderId, ?string $explicitKey): ?string
    {
        if ($endpoint->idempotency === IdempotencyMode::None) {
            return null;
        }
        if ($explicitKey !== null) {
            return $explicitKey;
        }
        if ($endpoint->keyFromOrderId && $orderId !== null) {
            return self::DERIVED_PREFIX . substr(hash('sha256', $requestUri . "\n" . $orderId), 0, 59);
        }

        return self::uuid4();
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return \sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
