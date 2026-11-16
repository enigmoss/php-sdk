<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * The server answered with something the SDK cannot read: not JSON, no envelope, or a field of
 * an unexpected type. On a 2xx the operation most likely succeeded (outcomeUnknown() is true).
 */
final class UnexpectedResponseException extends \RuntimeException implements EnigmossException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $requestId = null,
        public readonly ?string $idempotencyKey = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    private ?bool $outcomeOverride = null;

    public function outcomeUnknown(): bool
    {
        return $this->outcomeOverride ?? ($this->httpStatus !== null && $this->httpStatus >= 200 && $this->httpStatus < 300);
    }

    /**
     * Pins the outcome of the call this error ends. An unreadable answer of the fallback host
     * says nothing about the original request, which was not sent.
     *
     * @internal
     */
    public function withOutcomeUnknown(bool $unknown): self
    {
        $this->outcomeOverride = $unknown;

        return $this;
    }

    /**
     * @internal
     */
    public function withContext(int $httpStatus, ?string $requestId, ?string $idempotencyKey): self
    {
        return new self($this->getMessage(), $httpStatus, $requestId, $idempotencyKey, $this->getPrevious());
    }
}
