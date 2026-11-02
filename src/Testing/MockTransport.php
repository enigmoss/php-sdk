<?php

declare(strict_types=1);

namespace Enigmoss\Testing;

use Enigmoss\Exception\TransportException;
use Enigmoss\Http\HttpRequest;
use Enigmoss\Http\HttpResponse;
use Enigmoss\Http\TransportInterface;

/**
 * An in-memory transport for your tests: queue responses, then inspect what the SDK sent.
 *
 *     $transport = new MockTransport();
 *     $transport->queueData(Fixtures::payment(['status' => 'paid']));
 *     $enigmoss = new EnigmossClient($config, $transport);
 *     $enigmoss->payments->get($id);
 *     $request = $transport->lastRequest();
 */
final class MockTransport implements TransportInterface
{
    /** @var list<HttpResponse|TransportException|\Closure(HttpRequest): HttpResponse> */
    private array $queue = [];

    /** @var list<HttpRequest> */
    private array $requests = [];

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException(\sprintf('MockTransport: no response queued for %s %s.', $request->method, $request->url));
        }
        if ($next instanceof TransportException) {
            throw $next;
        }
        if ($next instanceof \Closure) {
            return $next($request);
        }

        return $next;
    }

    /**
     * @param HttpResponse|TransportException|(\Closure(HttpRequest): HttpResponse) $response
     */
    public function queue(HttpResponse|TransportException|\Closure $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    /**
     * Queues a successful envelope {"data": $data, "meta": $meta}.
     *
     * @param array<string, mixed> $meta
     * @param array<string, string> $headers
     */
    public function queueData(mixed $data, int $status = 200, array $meta = [], array $headers = []): self
    {
        $envelope = ['data' => $data];
        if ($meta !== []) {
            $envelope['meta'] = $meta;
        }

        return $this->queue(new HttpResponse($status, $headers + ['Content-Type' => 'application/json', 'X-Request-Id' => '01JTESTREQUEST0000000000000'], json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
    }

    /**
     * Queues an error envelope.
     *
     * @param array<string, mixed>  $details
     * @param list<array<string, mixed>> $violations
     * @param array<string, string> $headers
     */
    public function queueError(
        int $status,
        string $type,
        string $code,
        string $message = 'Error.',
        bool $retryable = false,
        array $details = [],
        array $violations = [],
        ?int $retryAfter = null,
        array $headers = [],
    ): self {
        $error = ['type' => $type, 'code' => $code, 'message' => $message, 'retryable' => $retryable];
        if ($details !== []) {
            $error['details'] = $details;
        }
        if ($violations !== []) {
            $error['violations'] = $violations;
        }
        $meta = ['request_id' => '01JTESTERROR00000000000000'];
        if ($retryAfter !== null) {
            $meta['retry_after'] = $retryAfter;
            $headers += ['Retry-After' => (string) $retryAfter];
        }

        return $this->queue(new HttpResponse($status, $headers + ['Content-Type' => 'application/json'], json_encode(['error' => $error, 'meta' => $meta], JSON_THROW_ON_ERROR)));
    }

    /**
     * @return list<HttpRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): ?HttpRequest
    {
        return $this->requests === [] ? null : $this->requests[\count($this->requests) - 1];
    }

    /**
     * Requests sent with this method whose path (after the host, without the query) starts with
     * $path, e.g. sent('POST', '/api/v4/payouts').
     *
     * @return list<HttpRequest>
     */
    public function sent(string $method, string $path): array
    {
        return array_values(array_filter($this->requests, static function (HttpRequest $request) use ($method, $path): bool {
            $requestPath = (string) parse_url($request->url, PHP_URL_PATH);

            return strtoupper($method) === $request->method && str_starts_with($requestPath, $path);
        }));
    }

    /**
     * The decoded JSON body of the last request ([] for a request without a body).
     *
     * @return array<array-key, mixed>
     */
    public function lastJson(): array
    {
        $body = $this->lastRequest()?->body;
        if ($body === null || $body === '') {
            return [];
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }

    public function pending(): int
    {
        return \count($this->queue);
    }
}
