<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Symfony\Http;

use Enigmoss\Exception\SendPhase;
use Enigmoss\Exception\TransportException;
use Enigmoss\Http\HttpRequest;
use Enigmoss\Http\HttpResponse;
use Enigmoss\Http\TransportInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends requests through Symfony HttpClient (profiler, MockHttpClient). Opt-in: the bundle uses
 * the built-in curl transport unless "transport: symfony" is configured.
 *
 * Symfony HttpClient does not tell a connection that was never established from a failure after
 * the request was sent, so failures are reported with SendPhase::Unknown and the API-origin
 * fallback (ClientConfig::$fallbackBaseUrl) never starts with this transport.
 */
final class SymfonyHttpClientTransport implements TransportInterface
{
    public function __construct(private readonly HttpClientInterface $client) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $options = [
            'headers' => $request->headers,
            'max_redirects' => 0,
            // "timeout" is an idle timeout in Symfony; "max_duration" bounds the whole request.
            'timeout' => $request->timeout,
            'max_duration' => $request->timeout,
        ];
        if ($request->body !== null) {
            $options['body'] = $request->body;
        }

        try {
            $response = $this->client->request($request->method, $request->url, $options);

            return new HttpResponse($response->getStatusCode(), $response->getHeaders(false), $response->getContent(false));
        } catch (TransportExceptionInterface $e) {
            throw new TransportException('HTTP request failed: ' . $e->getMessage(), SendPhase::Unknown, false, $e);
        }
    }
}
