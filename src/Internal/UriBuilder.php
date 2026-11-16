<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

/**
 * Builds the URL and the signed request URI. The URI is built once and the same string is both
 * signed and sent: the server signs the raw request URI, so re-encoding would break signatures.
 *
 * @internal
 */
final class UriBuilder
{
    /**
     * @param array<string, string|int|bool|null> $query Null values are dropped; order is kept.
     *
     * @param non-empty-string $origin
     *
     * @return array{url: non-empty-string, requestUri: string}
     */
    public static function build(string $origin, string $path, array $query): array
    {
        $requestUri = self::requestUri($path, $query);

        return ['url' => $origin . $requestUri, 'requestUri' => $requestUri];
    }

    /**
     * The signed part of the URL. It does not depend on the origin, so it stays the same when a
     * call moves to another API origin.
     *
     * @param array<string, string|int|bool|null> $query Null values are dropped; order is kept.
     */
    public static function requestUri(string $path, array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            if ($value === null) {
                continue;
            }
            $string = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($string);
        }

        // Never emit a bare "?": some server runtimes drop it and the signature would differ.
        return $pairs === [] ? $path : $path . '?' . implode('&', $pairs);
    }
}
