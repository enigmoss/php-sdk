<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

use Enigmoss\Auth\RequestSigner;
use Enigmoss\ClientConfig;
use Enigmoss\Exception\AuthenticationException;
use Enigmoss\Exception\ConflictException;
use Enigmoss\Exception\ErrorCode;
use Enigmoss\Exception\InvalidArgumentException;
use Enigmoss\Exception\TransportException;
use Enigmoss\Exception\UnexpectedResponseException;
use Enigmoss\Http\ClientEvent;
use Enigmoss\Http\ClientEventType;
use Enigmoss\Http\HttpRequest;
use Enigmoss\Http\HttpResponse;
use Enigmoss\Http\ObserverInterface;
use Enigmoss\Http\ResponseInfo;
use Enigmoss\Http\TransportInterface;
use Enigmoss\RequestOptions;
use Enigmoss\Retry\ClockInterface;
use Enigmoss\Retry\RetryClass;
use Enigmoss\Retry\SleeperInterface;
use Enigmoss\Version;

/**
 * Executes one API call: builds and signs the request, retries safely, maps the response.
 *
 * Each concern is a separate, separately tested step (UriBuilder, KeyResolver,
 * IdempotencyKeyResolver, RetryStrategy, ClockSkew, ErrorMapper); this class only orchestrates.
 * The body bytes and the Idempotency-Key are fixed before the first attempt and never change;
 * the timestamp and signature are fresh on every attempt.
 *
 * @internal
 */
final class Requester
{
    private readonly string $userAgent;

    /**
     * @param list<ObserverInterface> $observers
     */
    public function __construct(
        private readonly ClientConfig $config,
        private readonly TransportInterface $transport,
        private readonly RetryStrategy $retryStrategy,
        private readonly ClockSkew $clockSkew,
        private readonly ClockInterface $clock,
        private readonly SleeperInterface $sleeper,
        private readonly array $observers = [],
    ) {
        $userAgent = 'enigmoss-php-sdk/' . Version::SDK . ' PHP/' . PHP_VERSION;
        if ($config->appInfo !== null) {
            // Appended, never prepended: the server recognizes the SDK by the start of the header.
            $userAgent .= ' ' . $config->appInfo->toUserAgentToken();
        }
        $this->userAgent = $userAgent;
    }

    public function config(): ClientConfig
    {
        return $this->config;
    }

    /**
     * @param array<string, string>               $pathParams
     * @param array<string, string|int|bool|null> $query
     * @param array<string, mixed>|null           $body       Null for requests without a body.
     * @param string|null                         $orderId    order_id of a create request, for the derived Idempotency-Key.
     */
    public function execute(
        Endpoint $endpoint,
        array $pathParams = [],
        array $query = [],
        ?array $body = null,
        ?RequestOptions $options = null,
        ?string $orderId = null,
        ?float $maxRetryDelay = null,
    ): ApiResult {
        $options ??= new RequestOptions();

        $query = self::merge($query, $options->extraQuery, 'query parameter');
        $bodyBytes = $endpoint->method === 'GET' ? null : Json::encodeBody(self::merge($body ?? [], $options->extraBody, 'body field'));

        $uri = UriBuilder::build($this->config->origin, ClientConfig::API_PREFIX . $endpoint->resolvePath($pathParams), $query);
        $key = KeyResolver::resolve($this->config, $endpoint->scope, $endpoint->method, $uri['requestUri']);
        $idempotencyKey = IdempotencyKeyResolver::resolve($endpoint, $uri['requestUri'], $orderId, $options->idempotencyKey);

        $maxRetries = $options->maxRetries ?? $this->config->maxRetries;
        $maxRetryDelay = $options->maxRetryDelay ?? $maxRetryDelay ?? $this->config->maxRetryDelay;
        $timeout = $options->timeout ?? max($this->config->timeout, $endpoint->minTimeout ?? 0.0);
        $deadline = $this->clock->now() + $timeout * ($maxRetries + 1) + $maxRetryDelay * $maxRetries;

        $attempt = 0;
        $clockCorrected = false;
        $firstError = null;

        while (true) {
            ++$attempt;
            $timestamp = $this->clockSkew->timestamp($this->clock->now());
            $headers = [
                'Accept' => 'application/json',
                'User-Agent' => $this->userAgent,
                'X-Project-Uuid' => $this->config->projectId,
                'X-Timestamp' => (string) $timestamp,
                'X-Signature' => RequestSigner::signRequest(
                    $key,
                    $timestamp,
                    $this->config->projectId,
                    $endpoint->method,
                    $uri['requestUri'],
                    $bodyBytes ?? '',
                ),
            ];
            if ($bodyBytes !== null) {
                $headers['Content-Type'] = 'application/json';
            }
            if ($idempotencyKey !== null) {
                $headers['Idempotency-Key'] = $idempotencyKey;
            }
            $headers += $options->headers;

            $request = new HttpRequest($endpoint->method, $uri['url'], $headers, $bodyBytes, $timeout, $this->config->connectTimeout);
            $this->emit(new ClientEvent(ClientEventType::RequestSending, $endpoint->method, $uri['requestUri'], $attempt));
            $startedAt = $this->clock->now();

            try {
                $response = $this->transport->send($request);
            } catch (TransportException $e) {
                $e->withIdempotencyKey($idempotencyKey);
                $delay = $this->retryStrategy->afterTransportFailure($endpoint->retryClass, $e, $idempotencyKey !== null, $attempt);
                if (!$this->canRetry($delay, $attempt, $maxRetries, $deadline)) {
                    $this->emit(new ClientEvent(ClientEventType::RequestFailed, $endpoint->method, $uri['requestUri'], $attempt, error: $e));

                    throw $e;
                }
                $firstError ??= $e;
                $this->retryAfter((float) $delay, $endpoint->method, $uri['requestUri'], $attempt, $e);

                continue;
            }

            $duration = $this->clock->now() - $startedAt;
            $this->emit(new ClientEvent(
                ClientEventType::ResponseReceived,
                $endpoint->method,
                $uri['requestUri'],
                $attempt,
                $response->statusCode,
                $response->header('X-Request-Id'),
                $duration,
            ));

            if ($response->isSuccessful()) {
                $result = $this->success($response, $idempotencyKey, $attempt);
                if ($options->onResponse !== null) {
                    ($options->onResponse)($result->info);
                }

                return $result;
            }

            $previous = $firstError !== null && $endpoint->retryClass === RetryClass::MoneyCreate ? $firstError : null;
            $mapped = ErrorMapper::map($response, $idempotencyKey, $previous);
            $error = $mapped->exception;

            if ($error instanceof AuthenticationException && $error->errorCode === ErrorCode::TIMESTAMP_EXPIRED && !$clockCorrected) {
                $serverTime = $error->serverTime();
                if ($serverTime !== null && $this->clockSkew->adjust($serverTime, $this->clock->now())) {
                    // Authentication runs before any business logic: re-signing is always safe and
                    // does not count as a retry.
                    $clockCorrected = true;
                    --$attempt;

                    continue;
                }
            }

            $delay = $this->retryStrategy->afterErrorResponse($endpoint->retryClass, $mapped, $attempt, $maxRetryDelay);
            if (!$this->canRetry($delay, $attempt, $maxRetries, $deadline)) {
                $this->emit(new ClientEvent(ClientEventType::RequestFailed, $endpoint->method, $uri['requestUri'], $attempt, $response->statusCode, $response->header('X-Request-Id'), error: $error));

                throw $error;
            }
            if (!$error instanceof ConflictException || $error->errorCode !== ErrorCode::RESOURCE_CONFLICT) {
                $firstError ??= $error;
            }
            $this->retryAfter((float) $delay, $endpoint->method, $uri['requestUri'], $attempt, $error);
        }
    }

    private function success(HttpResponse $response, ?string $idempotencyKey, int $attempt): ApiResult
    {
        $info = ResponseInfo::fromResponse($response, $idempotencyKey, $attempt);

        try {
            $decoded = Json::decode($response->body);
        } catch (UnexpectedResponseException $e) {
            throw $e->withContext($response->statusCode, $info->requestId, $idempotencyKey);
        }

        if (!\is_array($decoded) || !\array_key_exists('data', $decoded)) {
            throw new UnexpectedResponseException('The response has no "data" member.', $response->statusCode, $info->requestId, $idempotencyKey);
        }

        /** @var array<string, mixed> $meta */
        $meta = \is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [];

        return new ApiResult($decoded['data'], $meta, $response->body, $info);
    }

    private function canRetry(?float $delay, int $attempt, int $maxRetries, float $deadline): bool
    {
        return $delay !== null && $attempt <= $maxRetries && $this->clock->now() + $delay < $deadline;
    }

    private function retryAfter(float $delay, string $method, string $path, int $attempt, \Throwable $error): void
    {
        $this->emit(new ClientEvent(ClientEventType::RetryScheduled, $method, $path, $attempt, retryDelaySeconds: $delay, error: $error));
        $this->sleeper->sleep($delay);
    }

    private function emit(ClientEvent $event): void
    {
        foreach ($this->observers as $observer) {
            try {
                $observer->onEvent($event);
            } catch (\Throwable) {
                // Observers must never break an API call.
            }
        }
    }

    /**
     * @template T
     *
     * @param array<string, T> $known
     * @param array<string, T> $extra
     *
     * @return array<string, T>
     */
    private static function merge(array $known, array $extra, string $what): array
    {
        foreach ($extra as $name => $value) {
            if (\array_key_exists($name, $known)) {
                throw new InvalidArgumentException(\sprintf('Extra %s "%s" would override a field the SDK sets; set it through the request object.', $what, $name));
            }
            $known[$name] = $value;
        }

        return $known;
    }
}
