<?php

declare(strict_types=1);

namespace Enigmoss\Http;

/**
 * Metadata of one API response: what to quote to support and how the call was executed.
 */
final class ResponseInfo
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?string $requestId,
        public readonly ?string $idempotencyKey,
        /** True when the server returned the stored response of an earlier request with the same Idempotency-Key. */
        public readonly bool $replayed,
        public readonly ?int $rateLimitLimit,
        public readonly ?int $rateLimitRemaining,
        /** Attempts made, 1 when the first attempt succeeded. */
        public readonly int $attempts,
        public readonly ?string $location = null,
    ) {}

    /**
     * @internal
     */
    public static function fromResponse(HttpResponse $response, ?string $idempotencyKey, int $attempts): self
    {
        $limit = $response->header('X-RateLimit-Limit');
        $remaining = $response->header('X-RateLimit-Remaining');

        return new self(
            statusCode: $response->statusCode,
            requestId: $response->header('X-Request-Id'),
            idempotencyKey: $idempotencyKey,
            replayed: strtolower((string) $response->header('Idempotent-Replayed')) === 'true',
            rateLimitLimit: self::int($limit),
            rateLimitRemaining: self::int($remaining),
            attempts: $attempts,
            location: $response->header('Location'),
        );
    }

    private static function int(?string $value): ?int
    {
        return $value !== null && preg_match('/^\d{1,10}$/', trim($value)) === 1 ? (int) trim($value) : null;
    }
}
