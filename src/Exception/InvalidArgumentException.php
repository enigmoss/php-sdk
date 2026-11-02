<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * A request object was built with an invalid value (malformed amount, UUID, order_id...).
 * Nothing was sent.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements EnigmossException
{
    public function outcomeUnknown(): bool
    {
        return false;
    }
}
