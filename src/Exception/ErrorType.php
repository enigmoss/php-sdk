<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * error.type of the API error envelope. New values may appear in minor releases; always keep a
 * default branch when matching on it.
 */
enum ErrorType: string
{
    case ValidationError = 'validation_error';
    case BusinessError = 'business_error';
    case AuthenticationError = 'authentication_error';
    case AuthorizationError = 'authorization_error';
    case NotFound = 'not_found';
    case Conflict = 'conflict';
    case RateLimitError = 'rate_limit_error';
    case DependencyError = 'dependency_error';
    case InternalError = 'internal_error';
    case Unknown = 'unknown';
}
