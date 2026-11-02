<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * The SDK is configured incorrectly (missing key for an endpoint, bad base URL, no transport).
 * Nothing was sent.
 */
final class ConfigurationException extends \LogicException implements EnigmossException
{
    public function outcomeUnknown(): bool
    {
        return false;
    }
}
