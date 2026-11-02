<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

use Enigmoss\Exception\ApiException;
use Enigmoss\Exception\UnexpectedResponseException;

/**
 * @internal
 */
final class MappedError
{
    public function __construct(
        public readonly ApiException|UnexpectedResponseException $exception,
        /** True when the body was the API error envelope (not a proxy or load balancer page). */
        public readonly bool $hasEnvelope,
        public readonly ?int $retryAfter,
    ) {}
}
