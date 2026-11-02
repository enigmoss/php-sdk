<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * A network-level failure: no HTTP response was received.
 *
 * Custom transports must throw this class (or ConnectionException) and report the send phase
 * honestly: retries of money-moving requests depend on it.
 */
class TransportException extends \RuntimeException implements EnigmossException
{
    private ?string $idempotencyKey = null;

    public function __construct(
        string $message,
        public readonly SendPhase $sendPhase = SendPhase::Unknown,
        public readonly bool $timedOut = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function outcomeUnknown(): bool
    {
        return $this->sendPhase !== SendPhase::NotSent;
    }

    /**
     * The Idempotency-Key the request was sent with; repeat the call with the same key.
     */
    public function idempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /**
     * @internal
     */
    public function withIdempotencyKey(?string $key): static
    {
        $this->idempotencyKey = $key;

        return $this;
    }
}
