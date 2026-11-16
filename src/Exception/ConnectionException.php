<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * The connection could not be established; the request was certainly not sent.
 */
final class ConnectionException extends TransportException
{
    /**
     * @param bool $hostUnreachable The connection to the API host itself was never established and no proxy is in the way; see TransportException.
     */
    public function __construct(string $message, ?\Throwable $previous = null, bool $hostUnreachable = false)
    {
        parent::__construct($message, SendPhase::NotSent, false, $previous, $hostUnreachable);
    }
}
