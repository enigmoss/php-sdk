<?php

declare(strict_types=1);

namespace Enigmoss\Http;

/**
 * A raw HTTP response as returned by a transport.
 */
final class HttpResponse
{
    /** @var array<string, list<string>> */
    public readonly array $headers;

    /**
     * @param array<array-key, string|array<array-key, string>> $headers Header name => value(s), any case.
     */
    public function __construct(
        public readonly int $statusCode,
        array $headers,
        public readonly string $body,
    ) {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $key = strtolower((string) $name);
            foreach ((array) $values as $value) {
                $normalized[$key][] = $value;
            }
        }
        $this->headers = $normalized;
    }

    /**
     * First value of a header, case-insensitive.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
