<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Support;

use Enigmoss\ClientConfig;
use Enigmoss\EnigmossClient;
use Enigmoss\Http\ObserverInterface;
use Enigmoss\Secret;
use Enigmoss\Testing\MockTransport;

final class ClientFactory
{
    public const PROJECT = '3f2b8c1e-5d4a-4e7b-9c6f-1a2b3c4d5e6f';
    public const PAYMENT_SECRET = 'test-payment-secret';
    public const PAYOUT_SECRET = 'test-payout-secret';

    /**
     * @param list<ObserverInterface> $observers
     */
    public static function make(
        MockTransport $transport,
        ?FakeClock $clock = null,
        bool $withPayout = true,
        int $maxRetries = 2,
        array $observers = [],
    ): EnigmossClient {
        $clock ??= new FakeClock();

        return new EnigmossClient(
            new ClientConfig(
                baseUrl: 'https://api.example.com',
                projectId: self::PROJECT,
                paymentKey: new Secret(self::PAYMENT_SECRET),
                payoutKey: $withPayout ? new Secret(self::PAYOUT_SECRET) : null,
                maxRetries: $maxRetries,
            ),
            $transport,
            $clock,
            $clock,
            $observers,
        );
    }
}
