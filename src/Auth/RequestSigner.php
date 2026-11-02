<?php

declare(strict_types=1);

namespace Enigmoss\Auth;

use Enigmoss\Secret;

/**
 * HMAC-SHA256 signatures of the Project API v4.
 *
 * Request:  hex(hmac_sha256("{timestamp}\n{project}\n{METHOD}\n{request uri with query}\n{raw body}", key))
 * Webhook:  hex(hmac_sha256("{timestamp}\n{project}\n{raw body}", payment key))
 *
 * The request URI is the path starting with "/api/v4" plus the query string exactly as sent.
 * The body is the exact byte string sent on the wire (empty for GET).
 */
final class RequestSigner
{
    public static function signRequest(
        Secret $key,
        int $timestamp,
        string $projectId,
        string $method,
        string $requestUri,
        string $body,
    ): string {
        $payload = $timestamp . "\n" . $projectId . "\n" . strtoupper($method) . "\n" . $requestUri . "\n" . $body;

        return hash_hmac('sha256', $payload, $key->reveal());
    }

    public static function signWebhook(Secret $key, string $timestamp, string $projectId, string $body): string
    {
        return hash_hmac('sha256', $timestamp . "\n" . $projectId . "\n" . $body, $key->reveal());
    }
}
