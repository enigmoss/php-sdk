<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Support;

use Enigmoss\Exception\TransportException;
use Enigmoss\Http\HttpRequest;
use Enigmoss\Http\HttpResponse;
use Enigmoss\Http\TransportInterface;

/**
 * A transport that answers by origin: every origin has its own list of replies, the last of
 * which repeats. A request to an origin without replies fails the test.
 */
final class RoutedTransport implements TransportInterface
{
    /** @var array<string, list<HttpResponse|TransportException|\Closure(HttpRequest): HttpResponse>> */
    private array $replies = [];

    /** @var list<HttpRequest> */
    private array $requests = [];

    /**
     * @param HttpResponse|TransportException|(\Closure(HttpRequest): HttpResponse) ...$replies
     */
    public function on(string $origin, HttpResponse|TransportException|\Closure ...$replies): self
    {
        $this->replies[$origin] = array_values($replies);

        return $this;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $origin = self::origin($request->url);
        $replies = $this->replies[$origin] ?? [];
        if ($replies === []) {
            throw new \LogicException(\sprintf('RoutedTransport: no reply for %s %s.', $request->method, $request->url));
        }
        $reply = $replies[0];
        if (\count($replies) > 1) {
            $this->replies[$origin] = \array_slice($replies, 1);
        }
        if ($reply instanceof TransportException) {
            throw $reply;
        }

        return $reply instanceof \Closure ? $reply($request) : $reply;
    }

    /**
     * @return list<HttpRequest>
     */
    public function to(string $origin): array
    {
        return array_values(array_filter($this->requests, static fn(HttpRequest $r): bool => self::origin($r->url) === $origin));
    }

    /**
     * @return list<string> The origin of every request, in order.
     */
    public function route(): array
    {
        return array_map(static fn(HttpRequest $r): string => self::origin($r->url), $this->requests);
    }

    private static function origin(string $url): string
    {
        return (string) preg_replace('~^(https?://[^/]+).*$~s', '$1', $url);
    }
}
