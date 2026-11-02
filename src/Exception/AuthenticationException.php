<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * Authentication failed: bad signature, expired timestamp, unknown project or inactive key (HTTP 401).
 *
 * A signature error with the right key usually means the wrong key type: payouts, refunds and
 * transfers need the payout key, everything else the payment key.
 */
final class AuthenticationException extends ApiException
{
    /**
     * Server Unix time from a auth.timestamp_expired error; compare with your clock.
     */
    public function serverTime(): ?int
    {
        $value = $this->details['server_time'] ?? null;

        return \is_int($value) ? $value : null;
    }
}
