<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * How far a failed HTTP attempt got before the transport gave up.
 */
enum SendPhase: string
{
    /** The request never left the client (DNS, connect, TLS handshake). Safe to retry. */
    case NotSent = 'not_sent';

    /** The request was sent; the server may have executed it. */
    case Sent = 'sent';

    /** The transport cannot tell. Treated exactly like Sent. */
    case Unknown = 'unknown';
}
