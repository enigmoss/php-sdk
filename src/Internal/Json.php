<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

use Enigmoss\Exception\InvalidArgumentException;
use Enigmoss\Exception\UnexpectedResponseException;

/**
 * @internal
 */
final class Json
{
    private const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * Encodes a request body. An empty body is "{}": the API expects a JSON object on every POST.
     *
     * @param array<string, mixed> $body
     */
    public static function encodeBody(array $body): string
    {
        if ($body === []) {
            return '{}';
        }

        try {
            return json_encode($body, self::ENCODE_FLAGS);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('The request body cannot be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function decode(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UnexpectedResponseException('The response body is not valid JSON: ' . $e->getMessage(), null, null, null, $e);
        }
    }
}
