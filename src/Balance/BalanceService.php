<?php

declare(strict_types=1);

namespace Enigmoss\Balance;

use Enigmoss\Auth\KeyScope;
use Enigmoss\Internal\Endpoint;
use Enigmoss\Internal\Payload;
use Enigmoss\Internal\Requester;
use Enigmoss\RequestOptions;

final class BalanceService
{
    /**
     * @internal
     */
    public function __construct(private readonly Requester $requester) {}

    /**
     * Business balances, one per currency. GET /api/v4/balances, payment key, permission info_balance.
     *
     * @return list<Balance>
     *
     * @throws \Enigmoss\Exception\EnigmossException
     */
    public function list(?RequestOptions $options = null): array
    {
        $result = $this->requester->execute(Endpoint::get('/balances', KeyScope::Payment), options: $options);

        return array_map(static fn(Payload $p): Balance => Balance::fromPayload($p), $result->payloads());
    }
}
