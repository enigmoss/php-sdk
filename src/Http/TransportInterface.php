<?php

declare(strict_types=1);

namespace Enigmoss\Http;

use Enigmoss\Exception\TransportException;

/**
 * Sends one HTTP attempt. Retries, signing and error mapping are done by the SDK, not here.
 *
 * Contract for implementations (see docs/custom-http-client.md):
 *  - send the URL string as given: no re-encoding, no added "?" or trailing slash;
 *  - send the body bytes and headers as given;
 *  - never follow redirects; return a 3xx response as is;
 *  - return any HTTP status (4xx and 5xx included) as an HttpResponse;
 *  - on a network failure throw TransportException and report how far the request got:
 *    SendPhase::NotSent (or ConnectionException) only when the request certainly did not leave
 *    the client, SendPhase::Unknown when unsure. Money-moving retries depend on this.
 */
interface TransportInterface
{
    /**
     * @throws TransportException
     */
    public function send(HttpRequest $request): HttpResponse;
}
