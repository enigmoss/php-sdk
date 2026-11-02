<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * The connection could not be established; the request was certainly not sent.
 */
final class ConnectionException extends TransportException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, SendPhase::NotSent, false, $previous);
    }
}
