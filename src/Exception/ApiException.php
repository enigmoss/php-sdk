<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * The API answered with an error envelope:
 * {"error": {"type", "code", "message", "retryable", "details"?, "violations"?}, "meta": {"request_id", "retry_after"?}}.
 *
 * Branch on the subclass or on $errorCode (see ErrorCode), never on the message text.
 * Quote $requestId when you contact support.
 */
class ApiException extends \RuntimeException implements EnigmossException
{
    /**
     * @param array<string, mixed> $details
     * @param list<Violation>      $violations
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly ErrorType $errorType,
        public readonly string $errorCode,
        public readonly bool $retryable,
        public readonly array $details = [],
        public readonly array $violations = [],
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $idempotencyKey = null,
        ?\Throwable $previous = null,
        ?string $serverMessage = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->serverMessage = $serverMessage ?? $message;
    }

    /**
     * error.message exactly as the server sent it. getMessage() adds the code, the request id and
     * a hint, so it is ready for logs and error trackers.
     */
    public readonly string $serverMessage;

    public function outcomeUnknown(): bool
    {
        return $this->httpStatus >= 500 || $this->errorCode === ErrorCode::IDEMPOTENCY_IN_PROGRESS;
    }

    /**
     * A details value as a string, when present.
     */
    public function detail(string $key): ?string
    {
        $value = $this->details[$key] ?? null;

        return \is_string($value) || \is_int($value) ? (string) $value : null;
    }
}
