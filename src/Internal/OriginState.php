<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

use Enigmoss\ClientConfig;

/**
 * The API origin the client talks to: the configured one, or the one the fallback host named.
 *
 * Mutable state of a client next to ClockSkew: the moved origin with its expiry and the time
 * until which the fallback is not asked again. Kept in memory and, when ClientConfig has an
 * origin store, shared with other processes through it.
 *
 * The store is outside the SDK, and what is read from it decides where requests go. Every
 * record therefore carries an HMAC made with the project key: a record written by anything that
 * does not know the key is ignored, and the origin passes the shape check again.
 *
 * @internal
 */
final class OriginState
{
    /** After a fallback that did not give a new origin, the fallback host is left alone this long. */
    public const PAUSE = 300;

    /** Bounds of refresh_after, the time a moved origin is remembered. */
    public const MIN_TTL = 3600;

    public const MAX_TTL = 604_800;

    /** @var non-empty-string|null */
    private ?string $moved = null;

    private float $movedUntil = 0.0;

    private float $pausedUntil = 0.0;

    private bool $loaded = false;

    private bool $probing = false;

    private readonly string $storeKey;

    public function __construct(private readonly ClientConfig $config)
    {
        $this->storeKey = 'enigmoss_origin_' . substr(hash('sha256', $config->origin . "\n" . $config->fallbackOrigin . "\n" . $config->projectId), 0, 32);
    }

    /**
     * @return non-empty-string
     */
    public function active(float $now): string
    {
        if ($this->config->fallbackOrigin === null) {
            return $this->config->origin;
        }
        if (!$this->loaded) {
            $this->reload($now);
        }

        return $this->moved !== null && $now < $this->movedUntil ? $this->moved : $this->config->origin;
    }

    public function paused(float $now): bool
    {
        return $now < $this->pausedUntil;
    }

    /**
     * Reads what another process stored: a moved origin, a pause, or both.
     */
    public function reload(float $now): void
    {
        $this->loaded = true;
        $store = $this->config->originStore;
        if ($store === null) {
            return;
        }

        try {
            $raw = $store->get($this->storeKey);
            $record = $raw === null ? null : json_decode($raw, true);
        } catch (\Throwable) {
            return; // A broken cache must not break API calls.
        }
        if (!\is_array($record)) {
            return;
        }

        $until = $record['until'] ?? null;
        $mac = $record['mac'] ?? null;
        if (!\is_string($mac) || !hash_equals($this->mac($record['origin'] ?? null, $until, $record['paused'] ?? null), $mac)) {
            return; // Not written by a client that knows the project key.
        }
        // An origin read from the cache passes the same check as one received from the
        // fallback host.
        $origin = ApiOrigin::fromResponse($record['origin'] ?? null, $this->config->allowInsecureHttp);
        if ($origin !== null && (\is_int($until) || \is_float($until)) && $until > $now && $until > $this->movedUntil) {
            $this->moved = $origin;
            $this->movedUntil = min((float) $until, $now + self::MAX_TTL);
        }
        $paused = $record['paused'] ?? null;
        if ((\is_int($paused) || \is_float($paused)) && $paused > $this->pausedUntil) {
            $this->pausedUntil = min((float) $paused, $now + self::PAUSE);
        }
    }

    /**
     * @param non-empty-string $origin       Already checked by ApiOrigin.
     * @param mixed            $refreshAfter refresh_after of the response, seconds.
     *
     * @return float Unix time until which the origin is used.
     */
    public function moveTo(string $origin, mixed $refreshAfter, float $now): float
    {
        $ttl = \is_int($refreshAfter) ? max(self::MIN_TTL, min(self::MAX_TTL, $refreshAfter)) : self::MIN_TTL;
        $this->moved = $origin;
        $this->movedUntil = $now + $ttl;
        $this->pausedUntil = 0.0;
        $this->save($now);

        return $this->movedUntil;
    }

    /**
     * Only the time is kept: neither the response nor the error of the failed fallback.
     */
    public function pause(float $now): void
    {
        $this->pausedUntil = $now + self::PAUSE;
        $this->save($now);
    }

    /**
     * One fallback lookup at a time per client. Plain PHP runs one call at a time; under a
     * coroutine runtime a second call finds the lookup running and does not start another.
     */
    public function beginProbe(): bool
    {
        if ($this->probing) {
            return false;
        }
        $this->probing = true;

        return true;
    }

    public function endProbe(): void
    {
        $this->probing = false;
    }

    /**
     * HMAC of a stored record, keyed with the project key and bound to the store key (base URL,
     * fallback host and project), so a record cannot be forged or moved to another entry.
     */
    private function mac(mixed $origin, mixed $until, mixed $paused): string
    {
        $key = $this->config->paymentKey ?? $this->config->payoutKey;
        $payload = "enigmoss-origin-store\n" . $this->storeKey . "\n" . json_encode([$origin, $until, $paused], JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', $payload, $key === null ? '' : $key->reveal());
    }

    private function save(float $now): void
    {
        $store = $this->config->originStore;
        if ($store === null) {
            return;
        }

        $moved = $this->moved !== null && $now < $this->movedUntil;
        $record = [
            'origin' => $moved ? $this->moved : null,
            'until' => $moved ? (int) ceil($this->movedUntil) : null,
            'paused' => $now < $this->pausedUntil ? (int) ceil($this->pausedUntil) : null,
        ];
        $record['mac'] = $this->mac($record['origin'], $record['until'], $record['paused']);
        $ttl = (int) ceil(max($moved ? $this->movedUntil : 0.0, $this->pausedUntil) - $now);

        try {
            $store->set($this->storeKey, (string) json_encode($record, JSON_UNESCAPED_SLASHES), max(1, $ttl));
        } catch (\Throwable) {
            // The origin stays in memory; the next process asks the fallback host itself.
        }
    }
}
