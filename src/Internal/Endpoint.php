<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

use Enigmoss\Auth\KeyScope;
use Enigmoss\Retry\RetryClass;

/**
 * Static description of one API operation.
 *
 * @internal
 */
final class Endpoint
{
    /**
     * @param non-empty-string $method
     * @param string $path Path after "/api/v4" with {placeholders}, e.g. "/payments/{id}/cancel".
     * @param bool   $keyFromOrderId Derive the default Idempotency-Key from the body order_id.
     * @param float|null $minTimeout Lower bound of the per-attempt timeout for slow operations, seconds.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly KeyScope $scope,
        public readonly RetryClass $retryClass,
        public readonly IdempotencyMode $idempotency = IdempotencyMode::None,
        public readonly bool $keyFromOrderId = false,
        public readonly ?float $minTimeout = null,
    ) {}

    /**
     * Creating a payout (and the payout leg of a refund) talks to the blockchain gateway
     * synchronously and may take up to ~160 seconds; a shorter client timeout would report an
     * unknown outcome for a payout that is still being created.
     */
    public const PAYOUT_TIMEOUT = 180.0;

    public static function get(string $path, KeyScope $scope): self
    {
        return new self('GET', $path, $scope, RetryClass::Read);
    }

    /**
     * A POST that creates an object identified by order_id; its default Idempotency-Key is
     * derived from the order_id, so a repeated call returns the stored response.
     */
    public static function create(string $path, KeyScope $scope, IdempotencyMode $mode, ?float $minTimeout = null): self
    {
        return new self('POST', $path, $scope, RetryClass::MoneyCreate, $mode, true, $minTimeout);
    }

    /**
     * A POST action protected by a random per-call Idempotency-Key.
     */
    public static function action(string $path, KeyScope $scope): self
    {
        return new self('POST', $path, $scope, RetryClass::MoneyCreate, IdempotencyMode::Supported);
    }

    /**
     * A POST without idempotency support: retried only when the request was not sent.
     */
    public static function nonIdempotent(string $path, KeyScope $scope): self
    {
        return new self('POST', $path, $scope, RetryClass::NonIdempotent);
    }

    /**
     * A side-effect-free POST (a calculation): retried like a read.
     */
    public static function calculation(string $path, KeyScope $scope): self
    {
        return new self('POST', $path, $scope, RetryClass::Read);
    }

    public static function patch(string $path, KeyScope $scope): self
    {
        return new self('PATCH', $path, $scope, RetryClass::Read);
    }

    /**
     * @param array<string, string> $params
     */
    public function resolvePath(array $params): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static function (array $match) use ($params): string {
                $name = $match[1];
                if (!isset($params[$name])) {
                    throw new \LogicException(\sprintf('Missing path parameter "%s".', $name));
                }

                return rawurlencode($params[$name]);
            },
            $this->path,
        );
    }
}
