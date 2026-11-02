<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * The Idempotency-Key was reused for a different request (idempotency.payload_mismatch) or the
 * first request with this key is still running (idempotency.in_progress, retryable).
 */
final class IdempotencyException extends ConflictException {}
