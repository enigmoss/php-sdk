<?php

declare(strict_types=1);

namespace Enigmoss\Retry;

/**
 * Retry safety class of an endpoint.
 */
enum RetryClass: string
{
    /** Reads and naturally idempotent updates: retried on any transient failure. */
    case Read = 'read';

    /**
     * Requests that create objects or move money. Retried only when it cannot cause a second
     * effect or hide the original error: the request was not sent, an Idempotency-Key protects it,
     * or the server says the request was not executed (429, lock busy, still in progress).
     */
    case MoneyCreate = 'money_create';

    /** Non-idempotent actions without an Idempotency-Key: retried only when nothing was sent. */
    case NonIdempotent = 'non_idempotent';
}
