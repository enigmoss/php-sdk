<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

use Enigmoss\Exception\ApiException;
use Enigmoss\Exception\AuthenticationException;
use Enigmoss\Exception\BusinessRuleException;
use Enigmoss\Exception\ConflictException;
use Enigmoss\Exception\DependencyException;
use Enigmoss\Exception\ErrorCode;
use Enigmoss\Exception\ErrorType;
use Enigmoss\Exception\IdempotencyException;
use Enigmoss\Exception\IpNotAllowedException;
use Enigmoss\Exception\NotFoundException;
use Enigmoss\Exception\PermissionDeniedException;
use Enigmoss\Exception\RateLimitException;
use Enigmoss\Exception\ServerException;
use Enigmoss\Exception\UnexpectedResponseException;
use Enigmoss\Exception\ValidationException;
use Enigmoss\Exception\Violation;
use Enigmoss\Http\HttpResponse;

/**
 * Turns a non-2xx response into a typed exception. The class is chosen by error.type (a closed,
 * stable taxonomy), falling back to the HTTP status when the body is not the API envelope.
 *
 * @internal
 */
final class ErrorMapper
{
    /**
     * @param string $prefix Put before the message, e.g. to say that another host answered.
     */
    public static function map(HttpResponse $response, ?string $idempotencyKey, ?\Throwable $previous = null, string $prefix = ''): MappedError
    {
        $requestId = $response->header('X-Request-Id');
        $retryAfter = self::retryAfterHeader($response);

        $decoded = null;
        try {
            $decoded = Json::decode($response->body);
        } catch (UnexpectedResponseException) {
            // Not JSON: a proxy or load balancer page. Handled below by status.
        }

        $error = \is_array($decoded) ? ($decoded['error'] ?? null) : null;
        if (!\is_array($error) || !\is_string($error['code'] ?? null)) {
            return new MappedError(self::withoutEnvelope($response, $requestId, $retryAfter, $idempotencyKey, $previous, $prefix), false, $retryAfter);
        }

        $meta = \is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [];
        $requestId = \is_string($meta['request_id'] ?? null) ? $meta['request_id'] : $requestId;
        if (\is_int($meta['retry_after'] ?? null)) {
            $retryAfter = $meta['retry_after'];
        }

        $code = $error['code'];
        $type = ErrorType::tryFrom(\is_string($error['type'] ?? null) ? $error['type'] : '') ?? ErrorType::Unknown;
        $message = \is_string($error['message'] ?? null) ? $error['message'] : $code;
        /** @var array<string, mixed> $details */
        $details = \is_array($error['details'] ?? null) ? $error['details'] : [];

        $class = self::classFor($type, $code, $response->statusCode);
        $exception = new $class(
            message: $prefix . self::describe($message, $code, $requestId, self::hint($code, $details, $retryAfter)),
            httpStatus: $response->statusCode,
            errorType: $type,
            errorCode: $code,
            retryable: ($error['retryable'] ?? false) === true,
            details: $details,
            violations: self::violations($error['violations'] ?? null),
            requestId: $requestId,
            retryAfter: $retryAfter,
            idempotencyKey: $idempotencyKey,
            previous: $previous,
            serverMessage: $message,
        );

        return new MappedError($exception, true, $retryAfter);
    }

    private static function describe(string $message, string $code, ?string $requestId, ?string $hint): string
    {
        $text = \sprintf('%s [%s%s]', rtrim($message), $code, $requestId === null ? '' : ', request ' . $requestId);

        return $hint === null ? $text : $text . ' ' . $hint;
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function hint(string $code, array $details, ?int $retryAfter): ?string
    {
        $permission = \is_string($details['permission'] ?? null) ? $details['permission'] : null;

        return match ($code) {
            ErrorCode::SIGNATURE_INVALID => 'Check that the key type fits the endpoint (payouts, refunds and transfers need the payout key), that the key belongs to this project, and that the body is sent as signed.',
            ErrorCode::TIMESTAMP_EXPIRED => 'Sync the server clock (NTP).',
            ErrorCode::PROJECT_UNKNOWN => 'Check ClientConfig::$projectId.',
            ErrorCode::KEY_INACTIVE => 'Enable the API for the project in the dashboard.',
            ErrorCode::IP_FORBIDDEN => "Add this server's IP address to the IP whitelist of the project in the dashboard.",
            ErrorCode::DOMAIN_FORBIDDEN => 'Use the API host shown in your dashboard as ClientConfig::$baseUrl.',
            ErrorCode::PERMISSION_DENIED => $permission === null
                ? 'Enable the API permission of this endpoint in the dashboard.'
                : \sprintf('Enable the "%s" API permission in the dashboard.', $permission),
            ErrorCode::IDEMPOTENCY_PAYLOAD_MISMATCH => 'This Idempotency-Key was used for a different request: keep one key per operation.',
            ErrorCode::IDEMPOTENCY_IN_PROGRESS => 'The first request with this key is still running: reconcile later with findByOrderId(), do not create a new order_id.',
            ErrorCode::RATE_LIMIT_EXCEEDED => $retryAfter === null ? null : \sprintf('Retry in %d s.', $retryAfter),
            default => null,
        };
    }

    /**
     * @return class-string<ApiException>
     */
    private static function classFor(ErrorType $type, string $code, int $status): string
    {
        return match ($type) {
            ErrorType::ValidationError => ValidationException::class,
            ErrorType::BusinessError => BusinessRuleException::class,
            ErrorType::AuthenticationError => AuthenticationException::class,
            ErrorType::AuthorizationError => $code === ErrorCode::IP_FORBIDDEN
                ? IpNotAllowedException::class
                : PermissionDeniedException::class,
            ErrorType::NotFound => NotFoundException::class,
            ErrorType::Conflict => str_starts_with($code, 'idempotency.') ? IdempotencyException::class : ConflictException::class,
            ErrorType::RateLimitError => RateLimitException::class,
            ErrorType::DependencyError => DependencyException::class,
            ErrorType::InternalError => ServerException::class,
            ErrorType::Unknown => self::classForStatus($status),
        };
    }

    /**
     * @return class-string<ApiException>
     */
    private static function classForStatus(int $status): string
    {
        return match (true) {
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionDeniedException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            $status === 400, $status === 415, $status === 422 => BusinessRuleException::class,
            $status >= 502 && $status <= 504 => DependencyException::class,
            $status >= 500 => ServerException::class,
            default => ApiException::class,
        };
    }

    private static function withoutEnvelope(
        HttpResponse $response,
        ?string $requestId,
        ?int $retryAfter,
        ?string $idempotencyKey,
        ?\Throwable $previous,
        string $prefix,
    ): ApiException|UnexpectedResponseException {
        $status = $response->statusCode;
        if ($status < 500 && $status !== 429) {
            $option = $prefix === '' ? 'baseUrl' : 'fallbackBaseUrl';
            $hint = $status === 404 ? \sprintf(' Check ClientConfig::$%s: the host does not serve this API.', $option) : '';

            return new UnexpectedResponseException(
                $prefix . \sprintf('Unexpected HTTP %d response without an API error body.%s', $status, $hint),
                $status,
                $requestId,
                $idempotencyKey,
                $previous,
            );
        }

        $class = self::classForStatus($status);
        $type = match (true) {
            $status === 429 => ErrorType::RateLimitError,
            $status >= 502 && $status <= 504 => ErrorType::DependencyError,
            default => ErrorType::InternalError,
        };

        return new $class(
            message: $prefix . \sprintf('HTTP %d from the API host or a proxy in front of it.', $status),
            httpStatus: $status,
            errorType: $type,
            errorCode: 'http.' . $status,
            retryable: $status === 429 || ($status >= 502 && $status <= 504),
            requestId: $requestId,
            retryAfter: $retryAfter,
            idempotencyKey: $idempotencyKey,
            previous: $previous,
        );
    }

    /**
     * @return list<Violation>
     */
    private static function violations(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $violations = [];
        foreach ($raw as $item) {
            if (!\is_array($item)) {
                continue;
            }
            /** @var array<string, mixed> $params */
            $params = \is_array($item['params'] ?? null) ? $item['params'] : [];
            $violations[] = new Violation(
                \is_string($item['field'] ?? null) ? $item['field'] : null,
                \is_string($item['rule'] ?? null) ? $item['rule'] : 'unknown',
                \is_string($item['message'] ?? null) ? $item['message'] : '',
                $params,
            );
        }

        return $violations;
    }

    private static function retryAfterHeader(HttpResponse $response): ?int
    {
        $value = $response->header('Retry-After');

        return $value !== null && preg_match('/^\d{1,10}$/', trim($value)) === 1 ? (int) trim($value) : null;
    }
}
