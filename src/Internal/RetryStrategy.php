<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

use Enigmoss\Exception\ApiException;
use Enigmoss\Exception\ErrorCode;
use Enigmoss\Exception\SendPhase;
use Enigmoss\Exception\TransportException;
use Enigmoss\Retry\RetryClass;

/**
 * Decides whether a failed attempt is retried and after which delay.
 *
 * The rules differ by RetryClass. On money-creating endpoints a business error the server marks
 * as retryable (dependency.*) is NOT retried: a failed payout keeps its order_id, so a retry
 * would turn the real cause into a 409 conflict.
 *
 * @internal
 */
final class RetryStrategy
{
    /**
     * Codes retried on money-creating endpoints: the request was not executed (rate limit, busy
     * lock, still in progress), or the API was unreachable behind its gateway
     * (dependency.unavailable, also returned by the gateway itself for 502/503/504). The
     * Idempotency-Key, always sent on these endpoints, turns a repeat of an executed request into
     * a replay. Business dependency errors (dependency.gateway, dependency.external_service) are
     * not here: a failed payout keeps its order_id, so a retry would hide the real cause.
     */
    private const SAFE_RETRY_CODES = [
        ErrorCode::RATE_LIMIT_EXCEEDED,
        ErrorCode::IDEMPOTENCY_IN_PROGRESS,
        ErrorCode::LOCK_NOT_ACQUIRED,
        ErrorCode::DEPENDENCY_UNAVAILABLE,
    ];

    /** @var \Closure(): float */
    private \Closure $random;

    /**
     * @param (\Closure(): float)|null $random Returns a float in [0, 1); injectable for tests.
     */
    public function __construct(
        private readonly float $baseDelay = 0.5,
        private readonly float $maxBackoff = 8.0,
        ?\Closure $random = null,
    ) {
        $this->random = $random ?? static fn(): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * @param int $attempt 1-based number of the attempt that failed.
     *
     * @return float|null Delay in seconds, or null when the failure must be thrown.
     */
    public function afterTransportFailure(RetryClass $class, TransportException $error, bool $hasIdempotencyKey, int $attempt): ?float
    {
        $retry = match (true) {
            $error->sendPhase === SendPhase::NotSent => true,
            $class === RetryClass::Read => true,
            $class === RetryClass::MoneyCreate => $hasIdempotencyKey,
            default => false,
        };

        return $retry ? $this->backoff($attempt) : null;
    }

    /**
     * @return float|null Delay in seconds, or null when the error must be thrown.
     */
    public function afterErrorResponse(RetryClass $class, MappedError $mapped, int $attempt, float $maxRetryDelay): ?float
    {
        $error = $mapped->exception;
        if (!$error instanceof ApiException) {
            return null;
        }

        if (!$mapped->hasEnvelope) {
            $gateway = $error->httpStatus >= 502 && $error->httpStatus <= 504;
            $retry = $class !== RetryClass::NonIdempotent && ($gateway || $error->httpStatus === 429);
        } else {
            $retry = match ($class) {
                RetryClass::Read => $error->retryable,
                RetryClass::MoneyCreate => \in_array($error->errorCode, self::SAFE_RETRY_CODES, true),
                RetryClass::NonIdempotent => false,
            };
        }

        if (!$retry) {
            return null;
        }
        if ($mapped->retryAfter !== null) {
            return $mapped->retryAfter <= $maxRetryDelay ? (float) $mapped->retryAfter : null;
        }

        return $this->backoff($attempt);
    }

    /**
     * Exponential backoff with full jitter.
     */
    public function backoff(int $attempt): float
    {
        $cap = min($this->maxBackoff, $this->baseDelay * (2 ** max(0, $attempt - 1)));

        return round(($this->random)() * $cap, 3);
    }
}
