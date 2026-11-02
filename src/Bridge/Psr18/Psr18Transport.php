<?php

declare(strict_types=1);

namespace Enigmoss\Bridge\Psr18;

use Enigmoss\Exception\ConnectionException;
use Enigmoss\Exception\SendPhase;
use Enigmoss\Exception\TransportException;
use Enigmoss\Http\HttpRequest;
use Enigmoss\Http\HttpResponse;
use Enigmoss\Http\TransportInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends requests through any PSR-18 client (Guzzle, Symfony Psr18Client, Buzz...).
 *
 *     new Psr18Transport($guzzle, $httpFactory, $httpFactory);
 *
 * Configure the client yourself: disable redirects ("allow_redirects" => false for Guzzle) and
 * set timeouts - PSR-18 has no per-request options, so RequestOptions::$timeout is not applied.
 * PSR-18 cannot tell whether a failed request was sent, so network errors are reported with
 * SendPhase::Unknown and money-moving requests are retried only when an Idempotency-Key protects them.
 */
final class Psr18Transport implements TransportInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public function send(HttpRequest $request): HttpResponse
    {
        // The URL string goes in as is: the signature covers the exact path and query.
        $psrRequest = $this->requestFactory->createRequest($request->method, $request->url);
        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }
        if ($request->body !== null) {
            // A fresh stream on every attempt: a consumed stream would send an empty body.
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($request->body));
        }

        try {
            $response = $this->client->sendRequest($psrRequest);
        } catch (RequestExceptionInterface $e) {
            // The client rejected the request before sending it.
            throw new ConnectionException('The HTTP client rejected the request: ' . $e->getMessage(), $e);
        } catch (NetworkExceptionInterface $e) {
            throw new TransportException('HTTP request failed: ' . $e->getMessage(), SendPhase::Unknown, false, $e);
        } catch (\Psr\Http\Client\ClientExceptionInterface $e) {
            throw new TransportException('HTTP request failed: ' . $e->getMessage(), SendPhase::Unknown, false, $e);
        }

        return new HttpResponse($response->getStatusCode(), $response->getHeaders(), (string) $response->getBody());
    }
}
