<?php

declare(strict_types=1);

namespace Enigmoss\ExchangeRate;

use Enigmoss\Auth\KeyScope;
use Enigmoss\Internal\Endpoint;
use Enigmoss\Internal\Payload;
use Enigmoss\Internal\Requester;
use Enigmoss\RequestOptions;

final class ExchangeRateService
{
    /**
     * @internal
     */
    public function __construct(private readonly Requester $requester) {}

    /**
     * Current exchange rates, optionally from one currency. GET /api/v4/exchange-rates, either key.
     *
     * @return list<ExchangeRate>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function list(?string $from = null, ?RequestOptions $options = null): array
    {
        $result = $this->requester->execute(Endpoint::get('/exchange-rates', KeyScope::Any), query: ['from' => $from], options: $options);

        return array_map(static fn(Payload $p): ExchangeRate => ExchangeRate::fromPayload($p), $result->payloads());
    }
}
