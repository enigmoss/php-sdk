<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * Marker for every exception thrown by the SDK. Catch it to handle all SDK failures at once.
 *
 * Not meant to be implemented outside the SDK: methods may be added in minor releases.
 */
interface EnigmossException extends \Throwable
{
    /**
     * True when the request may have been executed by the server even though the call failed
     * (connection lost after sending, 5xx, an unreadable 2xx, a request still in progress).
     *
     * When it is true for an operation that moves money, never retry with a new order_id:
     * repeat the call with the same order_id and Idempotency-Key, or look the object up with
     * findByOrderId() first.
     */
    public function outcomeUnknown(): bool;
}
