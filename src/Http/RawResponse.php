<?php

declare(strict_types=1);

namespace Enigmoss\Http;

/**
 * Result of EnigmossClient::request(): the decoded "data" of a successful response, its "meta",
 * the exact body and the response metadata.
 */
final class RawResponse
{
    /**
     * @param mixed                $data Decoded "data" member (arrays for JSON objects).
     * @param array<string, mixed> $meta Decoded "meta" member, [] when absent.
     */
    public function __construct(
        public readonly mixed $data,
        public readonly array $meta,
        public readonly string $body,
        public readonly ResponseInfo $info,
    ) {}
}
