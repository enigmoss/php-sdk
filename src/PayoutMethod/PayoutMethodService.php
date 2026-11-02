<?php

declare(strict_types=1);

namespace Enigmoss\PayoutMethod;

use Enigmoss\Auth\KeyScope;
use Enigmoss\Internal\Endpoint;
use Enigmoss\Internal\Payload;
use Enigmoss\Internal\Requester;
use Enigmoss\RequestOptions;

/**
 * Currencies and networks available for payouts. Works with either key.
 */
final class PayoutMethodService
{
    /**
     * @internal
     */
    public function __construct(private readonly Requester $requester) {}

    /**
     * GET /api/v4/payout-methods.
     *
     * @return list<PayoutMethod>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function list(?RequestOptions $options = null): array
    {
        $result = $this->requester->execute(Endpoint::get('/payout-methods', KeyScope::Any), options: $options);

        return array_map(static fn(Payload $p): PayoutMethod => PayoutMethod::fromPayload($p), $result->payloads());
    }

    /**
     * Limits, decimals, memo support and per-priority fees of one currency and network.
     * GET /api/v4/payout-methods/{currency}/{network}.
     *
     * @throws \Enigmoss\Exception\NotFoundException
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function get(string $currencyCode, string $networkCode, ?RequestOptions $options = null): PayoutMethodDetails
    {
        $result = $this->requester->execute(
            Endpoint::get('/payout-methods/{currency_code}/{network_code}', KeyScope::Any),
            ['currency_code' => $currencyCode, 'network_code' => $networkCode],
            options: $options,
        );

        return PayoutMethodDetails::fromPayload($result->payload());
    }
}
