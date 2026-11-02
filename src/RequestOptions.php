<?php

declare(strict_types=1);

namespace Enigmoss;

use Enigmoss\Exception\InvalidArgumentException;

/**
 * Per-call overrides. Every service method accepts it as the last argument.
 *
 *     $enigmoss->payouts->create($request, new RequestOptions(idempotencyKey: 'payout-42', timeout: 60));
 */
final class RequestOptions
{
    /**
     * Protocol headers, and the client-address and trust headers the API gateway sets itself
     * (the server takes the client IP for the IP whitelist from them).
     */
    private const RESERVED_HEADERS = [
        'accept', 'content-type', 'content-length', 'host', 'user-agent', 'idempotency-key',
        'x-project-uuid', 'x-timestamp', 'x-signature', 'expect',
        'x-real-ip', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port',
        'forwarded', 'x-proxy-token', 'x-project', 'x-http-method-override',
    ];

    /**
     * @param string|null                      $idempotencyKey Overrides the key the SDK would use (1-64 printable ASCII, no spaces).
     * @param float|null                       $timeout        Total time limit of one attempt, seconds.
     * @param int|null                         $maxRetries     Automatic retries for this call.
     * @param float|null                       $maxRetryDelay  The longest Retry-After to wait for in this call.
     * @param array<string, string>            $headers        Extra headers (tracing etc.); protocol headers cannot be overridden.
     * @param array<string, mixed>             $extraBody      Body fields the SDK does not model yet; may not override known fields.
     * @param array<string, string|int|bool>   $extraQuery     Query parameters the SDK does not model yet; may not override known ones.
     * @param (\Closure(\Enigmoss\Http\ResponseInfo): void)|null $onResponse Receives the metadata of the successful response
     *                                                    (request id, Idempotency-Key, replay flag, rate limit).
     */
    public function __construct(
        public readonly ?string $idempotencyKey = null,
        public readonly ?float $timeout = null,
        public readonly ?int $maxRetries = null,
        public readonly ?float $maxRetryDelay = null,
        public readonly array $headers = [],
        public readonly array $extraBody = [],
        public readonly array $extraQuery = [],
        public readonly ?\Closure $onResponse = null,
    ) {
        if ($idempotencyKey !== null && preg_match('/^[\x21-\x7E]{1,64}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('idempotencyKey must be 1-64 printable ASCII characters without spaces.');
        }
        if ($timeout !== null && $timeout <= 0) {
            throw new InvalidArgumentException('timeout must be a positive number of seconds.');
        }
        if ($maxRetries !== null && ($maxRetries < 0 || $maxRetries > 10)) {
            throw new InvalidArgumentException('maxRetries must be between 0 and 10.');
        }
        foreach ($headers as $name => $value) {
            if (\in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
                throw new InvalidArgumentException(\sprintf('Header "%s" is set by the SDK and cannot be overridden.', $name));
            }
            if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1 || preg_match('/[\r\n]/', $value) === 1) {
                throw new InvalidArgumentException(\sprintf('Invalid header "%s".', $name));
            }
        }
    }
}
