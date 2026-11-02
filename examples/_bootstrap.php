<?php

declare(strict_types=1);

// Shared setup of the examples. Without ENIGMOSS_EXAMPLE_MOCK the examples call the real API
// configured by ENIGMOSS_BASE_URL, ENIGMOSS_PROJECT_ID, ENIGMOSS_PAYMENT_KEY and
// ENIGMOSS_PAYOUT_KEY. With ENIGMOSS_EXAMPLE_MOCK=<scenario> they run offline against a
// MockTransport (see _mock.php): no request leaves the machine and no money moves.

require __DIR__ . '/../vendor/autoload.php';

use Enigmoss\ClientConfig;
use Enigmoss\EnigmossClient;
use Enigmoss\Retry\SleeperInterface;
use Enigmoss\Secret;
use Enigmoss\Testing\Fixtures;
use Enigmoss\Testing\MockTransport;

function requireEnv(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        fwrite(STDERR, "Set the {$name} environment variable (or ENIGMOSS_EXAMPLE_MOCK=happy to run offline).\n");
        exit(2);
    }

    return $value;
}

function exampleClient(string $example, bool $paymentKey = true, bool $payoutKey = false): EnigmossClient
{
    $scenario = getenv('ENIGMOSS_EXAMPLE_MOCK');
    if (is_string($scenario) && $scenario !== '') {
        /** @var array<string, array<string, Closure(MockTransport): void>> $scenarios */
        $scenarios = require __DIR__ . '/_mock.php';
        $setup = $scenarios[$example][$scenario] ?? null;
        if ($setup === null) {
            fwrite(STDERR, sprintf("Unknown mock scenario \"%s\"; available: %s\n", $scenario, implode(', ', array_keys($scenarios[$example] ?? []))));
            exit(2);
        }
        $transport = new MockTransport();
        $setup($transport);

        return new EnigmossClient(
            new ClientConfig('https://api.example.com', Fixtures::PROJECT_ID, new Secret('test-payment-key'), new Secret('test-payout-key')),
            $transport,
            sleeper: new class implements SleeperInterface {
                public function sleep(float $seconds): void {}
            },
        );
    }

    return new EnigmossClient(new ClientConfig(
        baseUrl: requireEnv('ENIGMOSS_BASE_URL'),
        projectId: requireEnv('ENIGMOSS_PROJECT_ID'),
        paymentKey: $paymentKey ? new Secret(requireEnv('ENIGMOSS_PAYMENT_KEY')) : null,
        payoutKey: $payoutKey ? new Secret(requireEnv('ENIGMOSS_PAYOUT_KEY')) : null,
    ));
}
