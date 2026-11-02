<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Examples;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs every example offline (ENIGMOSS_EXAMPLE_MOCK) and checks what an integrator copying it
 * would get. A request still in progress or an unknown outcome must never be reported as
 * "order_id taken" - that is how a second payout or refund gets created.
 */
final class ExamplesTest extends TestCase
{
    private const PAYMENT = '7c9e6679-7425-40de-944b-e07fc1f90ae7';
    private const CUSTOMER = 'TCustomerabcdefghijklmnopqrstuvwxy';
    private const RECIPIENT = 'TRecipientabcdefghijklmnopqrstuvwx';

    /**
     * @return iterable<string, array{string, string, list<string>, int, string}>
     */
    public static function scenarios(): iterable
    {
        yield 'create-payment happy' => ['create-payment', 'happy', [], 0, 'Redirect the customer to: https://'];
        yield 'create-payment order taken' => ['create-payment', 'order_taken', [], 0, 'order_id already used'];
        yield 'create-payment in progress' => ['create-payment', 'in_progress', [], 3, 'run again later with the same order_id'];
        yield 'refund happy' => ['refund', 'happy', [self::PAYMENT, self::CUSTOMER], 0, 'Refund 01970000'];
        yield 'refund order taken' => ['refund', 'order_taken', [self::PAYMENT, self::CUSTOMER], 0, 'order_id already used'];
        yield 'refund in progress' => ['refund', 'in_progress', [self::PAYMENT, self::CUSTOMER], 3, 'reconcile later with order_id'];
        yield 'payout happy' => ['payout-flow', 'happy', [self::RECIPIENT], 0, 'Payout 9b2d4f6a'];
        yield 'payout order taken' => ['payout-flow', 'order_taken', [self::RECIPIENT], 0, 'order_id already used'];
        yield 'payout unknown, found' => ['payout-flow', 'unknown_found', [self::RECIPIENT], 0, 'Payout 9b2d4f6a'];
        yield 'payout unknown, pending' => ['payout-flow', 'unknown_pending', [self::RECIPIENT], 3, 'keep the withdrawal pending'];
        yield 'payout in progress' => ['payout-flow', 'in_progress', [self::RECIPIENT], 3, 'keep the withdrawal pending'];
        yield 'static wallet' => ['static-wallet', 'happy', [], 0, 'Deposit '];
        yield 'auto pagination' => ['auto-pagination', 'happy', [], 0, 'order-42 is paid'];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('scenarios')]
    public function testExampleBehavesAsDocumented(string $example, string $scenario, array $arguments, int $exitCode, string $expected): void
    {
        $command = [PHP_BINARY, \dirname(__DIR__, 2) . '/examples/' . $example . '.php', ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['ENIGMOSS_EXAMPLE_MOCK' => $scenario] + getenv());
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $code = proc_close($process);

        self::assertSame($exitCode, $code, $output);
        self::assertStringContainsString($expected, $output);
        self::assertStringNotContainsString('Fatal error', $output);
        if ($code !== 0) {
            self::assertStringNotContainsString('already used', $output, 'An unknown outcome was reported as a taken order_id.');
        }
    }
}
