<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

use Enigmoss\Auth\KeyScope;
use Enigmoss\Auth\RequestSigner;
use Enigmoss\ClientConfig;
use Enigmoss\Exception\ApiException;
use Enigmoss\Exception\AuthenticationException;
use Enigmoss\Exception\ConflictException;
use Enigmoss\Exception\ErrorCode;
use Enigmoss\Exception\InvalidArgumentException;
use Enigmoss\Exception\SendPhase;
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
use Enigmoss\Secret;
use Enigmoss\Version;

/**
 * Executes one API call: builds and signs the request, retries safely, maps the response.
 *
 * Each concern is a separate, separately tested step (UriBuilder, KeyResolver,
 * IdempotencyKeyResolver, RetryStrategy, ClockSkew, ErrorMapper); this class only orchestrates.
 * The body bytes and the Idempotency-Key are fixed before the first attempt and never change;
 * the timestamp and signature are fresh on every attempt.
 *
 * API-origin fallback (only with ClientConfig::$fallbackBaseUrl): when the retries end with a
 * failure that proves the API host could not be reached, the fallback host is asked once for the
 * current API origin of the project (a signed GET of the platform brand, outside the retry loop)
 * and the same request - same body, same Idempotency-Key - starts over on that origin.
 *
 * @internal
 */
final class Requester
{
    /** The signed request the fallback host answers with the API origin of the project. */
    private const PROBE_URI = ClientConfig::API_PREFIX . '/project/platform-brand';

    /** Time limit of the fallback lookup, seconds; it must fit into what is left of the call. */
    private const PROBE_TIMEOUT = 3.0;

    /** Accepted distance between X-Response-Timestamp and the (drift-corrected) clock, seconds. */
    private const RESPONSE_TOLERANCE = 300;

    private const FALLBACK_PREFIX = 'API origin fallback failed: ';

    private readonly string $userAgent;

    private readonly OriginState $origins;

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
        ?OriginState $origins = null,
    ) {
        $this->origins = $origins ?? new OriginState($config);
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
     * The API origin requests go to now: the configured one, or the one the fallback host named.
     *
     * @return non-empty-string
     */
    public function origin(): string
    {
        return $this->origins->active($this->clock->now());
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

        // The request URI is signed and does not depend on the origin; the URL is put together
        // on every attempt, because the origin can change in the middle of a call.
        $requestUri = UriBuilder::requestUri(ClientConfig::API_PREFIX . $endpoint->resolvePath($pathParams), $query);
        $key = KeyResolver::resolve($this->config, $endpoint->scope, $endpoint->method, $requestUri);
        $idempotencyKey = IdempotencyKeyResolver::resolve($endpoint, $requestUri, $orderId, $options->idempotencyKey);

        $maxRetries = $options->maxRetries ?? $this->config->maxRetries;
        $maxRetryDelay = $options->maxRetryDelay ?? $maxRetryDelay ?? $this->config->maxRetryDelay;
        $timeout = $options->timeout ?? max($this->config->timeout, $endpoint->minTimeout ?? 0.0);
        $deadline = $this->clock->now() + $timeout * ($maxRetries + 1) + $maxRetryDelay * $maxRetries;

        $origin = $this->origins->active($this->clock->now());
        $fallbackDone = false;
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
                    $requestUri,
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

            $request = new HttpRequest($endpoint->method, $origin . $requestUri, $headers, $bodyBytes, $timeout, $this->config->connectTimeout);
            $this->emit(new ClientEvent(ClientEventType::RequestSending, $endpoint->method, $requestUri, $attempt));
            $startedAt = $this->clock->now();

            try {
                $response = $this->transport->send($request);
            } catch (TransportException $e) {
                $e->withIdempotencyKey($idempotencyKey);
                $delay = $this->retryStrategy->afterTransportFailure($endpoint->retryClass, $e, $idempotencyKey !== null, $attempt);
                if (!$this->canRetry($delay, $attempt, $maxRetries, $deadline)) {
                    if (!$fallbackDone && $this->config->fallbackOrigin !== null && $e->hostUnreachable && $e->sendPhase === SendPhase::NotSent) {
                        // One fallback per call, and only now: the retries are over and the
                        // last attempt still could not reach the API host.
                        $fallbackDone = true;

                        try {
                            $found = $this->fallback($e, $origin, $this->config->fallbackOrigin, $deadline, $idempotencyKey, $endpoint->method, $requestUri, $attempt);
                        } catch (ApiException|UnexpectedResponseException $failure) {
                            $this->emit(new ClientEvent(ClientEventType::RequestFailed, $endpoint->method, $requestUri, $attempt, $failure->httpStatus, $failure->requestId, error: $failure));

                            throw $failure;
                        }

                        if ($found !== null && $found['response'] !== null && $endpoint->method === 'GET' && $requestUri === self::PROBE_URI && $found['key'] === $key) {
                            // The call is the lookup itself: the signed answer of the fallback
                            // host is its result.
                            $response = $found['response'];
                            $this->emit(new ClientEvent(ClientEventType::ResponseReceived, $endpoint->method, $requestUri, $attempt, $response->statusCode, $response->header('X-Request-Id')));
                            $result = $this->success($response, $idempotencyKey, $attempt);
                            if ($options->onResponse !== null) {
                                ($options->onResponse)($result->info);
                            }

                            return $result;
                        }
                        if ($found !== null && $found['origin'] !== null) {
                            // Same body, same Idempotency-Key, a fresh timestamp and signature;
                            // the attempts start over, the deadline of the call does not.
                            $origin = $found['origin'];
                            $attempt = 0;

                            continue;
                        }
                    }
                    $this->emit(new ClientEvent(ClientEventType::RequestFailed, $endpoint->method, $requestUri, $attempt, error: $e));

                    throw $e;
                }
                $firstError ??= $e;
                $this->retryAfter((float) $delay, $endpoint->method, $requestUri, $attempt, $e);

                continue;
            }

            $duration = $this->clock->now() - $startedAt;
            $this->emit(new ClientEvent(
                ClientEventType::ResponseReceived,
                $endpoint->method,
                $requestUri,
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
                $this->emit(new ClientEvent(ClientEventType::RequestFailed, $endpoint->method, $requestUri, $attempt, $response->statusCode, $response->header('X-Request-Id'), error: $error));

                throw $error;
            }
            if (!$error instanceof ConflictException || $error->errorCode !== ErrorCode::RESOURCE_CONFLICT) {
                $firstError ??= $error;
            }
            $this->retryAfter((float) $delay, $endpoint->method, $requestUri, $attempt, $error);
        }
    }

    /**
     * Asks the fallback host for the API origin of the project.
     *
     * @param non-empty-string $failedOrigin The origin the attempts of this call could not reach.
     * @param non-empty-string $fallback
     *
     * @return array{origin: non-empty-string|null, response: HttpResponse|null, key: Secret|null}|null
     *                                                                                               Null: nothing to go on, throw the original error. "origin" is the origin to repeat the call on (null when the
     *                                                                                               fallback host named the origin that just failed); "response" is its signed answer when one was received.
     *
     * @throws ApiException|UnexpectedResponseException The fallback host answered, but not with a usable origin.
     */
    private function fallback(
        TransportException $cause,
        string $failedOrigin,
        string $fallback,
        float $deadline,
        ?string $idempotencyKey,
        string $method,
        string $requestUri,
        int $attempt,
    ): ?array {
        $now = $this->clock->now();
        $this->origins->reload($now);
        $active = $this->origins->active($now);
        if (ApiOrigin::normalize($active) !== ApiOrigin::normalize($failedOrigin)) {
            // Another call or another process has moved already: no need to ask again.
            return ['origin' => $active, 'response' => null, 'key' => null];
        }
        if ($this->origins->paused($now) || $deadline - $now < self::PROBE_TIMEOUT || !$this->origins->beginProbe()) {
            return null;
        }

        try {
            return $this->probe($cause, $failedOrigin, $fallback, $deadline, $idempotencyKey, $method, $requestUri, $attempt);
        } finally {
            $this->origins->endProbe();
        }
    }

    /**
     * One signed GET of the platform brand on the fallback host, straight through the transport:
     * no retries, no recursion. Every outcome but a new origin pauses the fallback. The answer is
     * trusted only when it is signed with the project key for this very request.
     *
     * @param non-empty-string $failedOrigin
     * @param non-empty-string $fallback
     *
     * @return array{origin: non-empty-string|null, response: HttpResponse, key: Secret}|null
     */
    private function probe(
        TransportException $cause,
        string $failedOrigin,
        string $fallback,
        float $deadline,
        ?string $idempotencyKey,
        string $method,
        string $requestUri,
        int $attempt,
    ): ?array {
        $key = KeyResolver::resolve($this->config, KeyScope::Any, 'GET', self::PROBE_URI);
        $clockCorrected = false;

        while (true) {
            // A fresh nonce in the signed URI makes the request signature unique. The response
            // signature covers it, so a recorded answer fits no other request - not even one
            // signed for the same second after the fallback host itself corrected the clock.
            $probeUri = self::PROBE_URI . '?nonce=' . bin2hex(random_bytes(16));
            $timestamp = $this->clockSkew->timestamp($this->clock->now());
            $signature = RequestSigner::signRequest($key, $timestamp, $this->config->projectId, 'GET', $probeUri, '');
            $request = new HttpRequest(
                'GET',
                $fallback . $probeUri,
                [
                    'Accept' => 'application/json',
                    'User-Agent' => $this->userAgent,
                    'X-Project-Uuid' => $this->config->projectId,
                    'X-Timestamp' => (string) $timestamp,
                    'X-Signature' => $signature,
                ],
                null,
                self::PROBE_TIMEOUT,
                min(self::PROBE_TIMEOUT, $this->config->connectTimeout),
            );

            try {
                $response = $this->transport->send($request);
            } catch (TransportException) {
                // The fallback host is out of reach too: the caller gets the original error.
                $this->origins->pause($this->clock->now());

                return null;
            }
            if ($response->isSuccessful()) {
                break;
            }

            $error = ErrorMapper::map($response, $idempotencyKey, $cause, self::FALLBACK_PREFIX)->exception;
            if ($error instanceof AuthenticationException && $error->errorCode === ErrorCode::TIMESTAMP_EXPIRED && !$clockCorrected) {
                $serverTime = $error->serverTime();
                if ($serverTime !== null && $this->clockSkew->adjust($serverTime, $this->clock->now()) && $deadline - $this->clock->now() >= self::PROBE_TIMEOUT) {
                    $clockCorrected = true;

                    continue;
                }
            }
            $this->origins->pause($this->clock->now());

            // The original request was not sent: the status of this answer says nothing about it.
            throw $error->withOutcomeUnknown($cause->outcomeUnknown());
        }

        $problem = $this->responseSignatureProblem($response, $key, $signature);
        if ($problem !== null) {
            throw $this->rejected($problem, $response, $idempotencyKey, $cause);
        }

        try {
            $decoded = Json::decode($response->body);
        } catch (UnexpectedResponseException) {
            $decoded = null;
        }
        $data = \is_array($decoded) && \is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $value = \is_array($data['api'] ?? null) ? ($data['api']['origin'] ?? null) : null;
        $origin = ApiOrigin::fromResponse($value, $this->config->allowInsecureHttp);
        if ($origin === null) {
            throw $this->rejected(
                $value === null
                    ? 'the response has no data.api.origin.'
                    : 'data.api.origin of the response is not a plain https origin such as "https://api.example.com".',
                $response,
                $idempotencyKey,
                $cause,
            );
        }

        $now = $this->clock->now();
        if ($origin === ApiOrigin::normalize($failedOrigin)) {
            // The fallback host names the origin that just failed: the trouble is on this side.
            $this->origins->pause($now);

            return ['origin' => null, 'response' => $response, 'key' => $key];
        }

        $this->origins->moveTo($origin, $data['refresh_after'] ?? null, $now);
        $this->emit(new ClientEvent(ClientEventType::OriginChanged, $method, $requestUri, $attempt, origin: $origin, previousOrigin: $failedOrigin, error: $cause));

        return ['origin' => $origin, 'response' => $response, 'key' => $key];
    }

    /**
     * The fallback host answered 2xx, but not with an origin the SDK may trust. The original
     * request was not sent, so the outcome of the call is the one of the original error.
     */
    private function rejected(string $problem, HttpResponse $response, ?string $idempotencyKey, TransportException $cause): UnexpectedResponseException
    {
        $this->origins->pause($this->clock->now());

        return (new UnexpectedResponseException(
            self::FALLBACK_PREFIX . $problem,
            $response->statusCode,
            $response->header('X-Request-Id'),
            $idempotencyKey,
            $cause,
        ))->withOutcomeUnknown($cause->outcomeUnknown());
    }

    /**
     * Checks X-Response-Timestamp and X-Response-Signature of the fallback answer. The signature
     * covers the signature of the request, so the answer belongs to this very request.
     *
     * @return string|null What is wrong, null when the response is authentic.
     */
    private function responseSignatureProblem(HttpResponse $response, Secret $key, string $requestSignature): ?string
    {
        $timestamp = $response->header('X-Response-Timestamp');
        $signature = $response->header('X-Response-Signature');
        if ($timestamp === null || $signature === null) {
            return 'the response is not signed (no X-Response-Timestamp or X-Response-Signature header), so its API origin is not trusted.';
        }
        if (preg_match('/^\d{1,12}\z/', $timestamp) !== 1
            || abs((int) $timestamp - $this->clockSkew->timestamp($this->clock->now())) > self::RESPONSE_TOLERANCE) {
            return \sprintf('X-Response-Timestamp of the response is not within %d s of the clock, so its API origin is not trusted.', self::RESPONSE_TOLERANCE);
        }
        if (!hash_equals(RequestSigner::signResponse($key, $timestamp, $requestSignature, $response->body), strtolower($signature))) {
            return 'the response signature does not match the project key, so its API origin is not trusted.';
        }

        return null;
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
