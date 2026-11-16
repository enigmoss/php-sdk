<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

/**
 * Shape check of an API origin received from the fallback host.
 *
 * The origin decides where signed requests with business data go next, so only a plain public
 * https origin is accepted: a host name of at least two labels whose last label starts with a
 * letter (no IP literal, no "localhost"), port 443 or none, and nothing after the host. The
 * check runs on the raw string, without a URL parser, so it reads the same in every official SDK.
 *
 * @internal
 */
final class ApiOrigin
{
    private const MAX_LENGTH = 2048;

    private const MAX_HOST_LENGTH = 253;

    private const HTTPS = '/^https:\/\/((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9])(?::443)?\z/i';

    /** Plain http is for a local API only: loopback hosts, and only under allowInsecureHttp. */
    private const LOOPBACK_HTTP = '/^http:\/\/(localhost|127\.0\.0\.1|\[::1\])(?::(\d{1,5}))?\z/i';

    /**
     * @return non-empty-string|null The normalized origin, null when the value is not acceptable.
     */
    public static function fromResponse(mixed $value, bool $allowInsecureHttp): ?string
    {
        if (!\is_string($value) || \strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        if (preg_match(self::HTTPS, $value, $match) === 1) {
            $host = strtolower($match[1]);
            if (\strlen($host) > self::MAX_HOST_LENGTH || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) {
                return null;
            }

            return 'https://' . $host;
        }

        if ($allowInsecureHttp && preg_match(self::LOOPBACK_HTTP, $value, $match) === 1) {
            $port = $match[2] ?? '';

            return 'http://' . strtolower($match[1]) . ($port === '' || $port === '80' ? '' : ':' . $port);
        }

        return null;
    }

    /**
     * Lower case, without the default port: the form origins are compared in.
     */
    public static function normalize(string $origin): string
    {
        $origin = strtolower($origin);
        if (str_starts_with($origin, 'https://') && str_ends_with($origin, ':443')) {
            return substr($origin, 0, -4);
        }
        if (str_starts_with($origin, 'http://') && str_ends_with($origin, ':80')) {
            return substr($origin, 0, -3);
        }

        return $origin;
    }
}
