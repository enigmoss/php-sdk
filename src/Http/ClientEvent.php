<?php

declare(strict_types=1);

namespace Enigmoss\Http;

/**
 * What happened during a call, for logging, metrics and tracing. Never contains bodies,
 * headers or secrets.
 */
final class ClientEvent
{
    public function __construct(
        public readonly ClientEventType $type,
        public readonly string $method,
        /** Request path with query, starting with "/api/v4". */
        public readonly string $path,
        /** 1-based attempt number. */
        public readonly int $attempt,
        public readonly ?int $statusCode = null,
        public readonly ?string $requestId = null,
        public readonly ?float $durationSeconds = null,
        /** Delay before the next attempt (RetryScheduled only). */
        public readonly ?float $retryDelaySeconds = null,
        public readonly ?\Throwable $error = null,
    ) {}
}
