<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

/**
 * Corrects request timestamps when the local clock drifts.
 *
 * The offset lives in the client instance and is the only mutable state of the SDK. It is
 * recomputed from every server_time the server reports (never accumulated) and clamped; a
 * concurrent write is harmless because the last value wins.
 *
 * @internal
 */
final class ClockSkew
{
    /** Drift below this is network latency, not a wrong clock. */
    private const MIN_DRIFT = 30;

    private const MAX_DRIFT = 86_400;

    private int $offset = 0;

    public function timestamp(float $now): int
    {
        return (int) floor($now) + $this->offset;
    }

    /**
     * @return bool True when the offset was changed and the request should be signed again.
     */
    public function adjust(int $serverTime, float $now): bool
    {
        $drift = $serverTime - (int) floor($now);
        if (abs($drift) < self::MIN_DRIFT) {
            return false;
        }

        $offset = max(-self::MAX_DRIFT, min(self::MAX_DRIFT, $drift));
        if ($offset === $this->offset) {
            return false;
        }
        $this->offset = $offset;

        return true;
    }

    public function offset(): int
    {
        return $this->offset;
    }
}
