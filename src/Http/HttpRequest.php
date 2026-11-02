<?php

declare(strict_types=1);

namespace Enigmoss\Http;

/**
 * A fully prepared, signed HTTP request. Transports must send it exactly as is: the URL string
 * (path and query bytes), the headers and the body are covered by the signature.
 */
final class HttpRequest
{
    /**
     * @param non-empty-string      $method         Upper-case HTTP method.
     * @param non-empty-string      $url            Absolute URL; never re-encode or normalize it.
     * @param array<string, string> $headers        Header name => value.
     * @param string|null           $body           Raw body bytes, null for no body.
     * @param float                 $timeout        Total time limit, seconds.
     * @param float                 $connectTimeout Connect time limit, seconds.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly float $timeout,
        public readonly float $connectTimeout,
    ) {}
}
