<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * Too many requests (HTTP 429). Wait $retryAfter seconds before the next attempt.
 */
final class RateLimitException extends ApiException {}
