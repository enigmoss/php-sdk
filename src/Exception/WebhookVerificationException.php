<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * An incoming webhook did not pass verification. Answer it with HTTP 400 and do not process it.
 */
final class WebhookVerificationException extends \RuntimeException implements EnigmossException
{
    public function __construct(string $message, public readonly WebhookFailureReason $reason)
    {
        parent::__construct($message);
    }

    public function outcomeUnknown(): bool
    {
        return false;
    }
}
