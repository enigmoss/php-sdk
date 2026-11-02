<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Enigmoss\Exception\BusinessRuleException;
use Enigmoss\Exception\ConflictException;
use Enigmoss\Exception\EnigmossException;
use Enigmoss\Exception\IpNotAllowedException;
use Enigmoss\Payout\CreatePayoutRequest;
use Enigmoss\Payout\Payout;
use Enigmoss\Payout\QuotePayoutRequest;

/**
 * Quote, create and reconcile a payout. Requires the payout key and a whitelisted server IP.
 *
 *   php examples/payout-flow.php <recipient-address> [amount] [order-id]
 *   ENIGMOSS_EXAMPLE_MOCK=happy|order_taken|unknown_found|unknown_pending|in_progress php examples/payout-flow.php TRecipientabcdefghijklmnopqrstuvwx
 *
 * Re-run with the same order-id to see that a repeated create never pays twice.
 */
if (!isset($argv[1])) {
    fwrite(STDERR, "Usage: php examples/payout-flow.php <recipient-address> [amount] [order-id]\n");
    exit(2);
}

$address = $argv[1];
$amount = $argv[2] ?? '10.00';
$orderId = $argv[3] ?? 'withdrawal-' . bin2hex(random_bytes(4));

$enigmoss = exampleClient('payout-flow', paymentKey: false, payoutKey: true);

// 1. Quote: fee and amounts, nothing is created or debited.
try {
    $quote = $enigmoss->payouts->quote(new QuotePayoutRequest(
        amount: $amount,
        currencyCode: 'USDT',
        address: $address,
        networkCode: 'tron',
    ));
} catch (IpNotAllowedException $e) {
    fwrite(STDERR, "This server IP is not allowed for the payout key: add it to the IP whitelist in the dashboard.\n");
    exit(1);
} catch (BusinessRuleException $e) {
    fwrite(STDERR, 'Cannot quote: ' . $e->getMessage() . "\n");
    exit(1);
}

printf(
    "Quote: recipient gets %s %s, you are charged %s (fee %s)\n",
    $quote->recipient->amount ?? '?',
    $quote->recipient->currencyCode ?? '',
    $quote->charged->amount ?? '?',
    $quote->charged->fee ?? '?',
);

// 2. Create. The Idempotency-Key is derived from the order_id, so repeating this call returns
//    the same payout. NEVER switch to a new order_id for the same withdrawal.
$request = new CreatePayoutRequest(
    orderId: $orderId,
    amount: $amount,
    currencyCode: 'USDT',
    address: $address,
    networkCode: 'tron',
);

try {
    $payout = $enigmoss->payouts->create($request);
} catch (EnigmossException $e) {
    if ($e->outcomeUnknown()) {
        // 3. Outcome unknown (timeout, 5xx, still in progress): the payout may exist or still be
        //    being created - creation can take up to ~160 s. Reconcile with the same order_id.
        fwrite(STDERR, "Outcome unknown, checking order_id {$orderId}...\n");
        $payout = $enigmoss->payouts->findByOrderId($orderId);
        if ($payout === null) {
            // Not visible yet. Do NOT create again in this run: keep the withdrawal "pending
            // reconcile" in your database and check again later (cron, or the payout.* webhook).
            // After a few minutes without a payout, repeating create() with the SAME order_id is safe.
            fwrite(STDERR, "Not visible yet: keep the withdrawal pending and reconcile later with order_id {$orderId}.\n");
            exit(3);
        }
    } elseif ($e instanceof ConflictException && $e->isOrderIdTaken()) {
        // The order_id belongs to an earlier payout (a failed payout keeps its order_id too).
        $payout = $enigmoss->payouts->get((string) $e->existingResourceId());
        echo "order_id already used, showing the existing payout.\n";
    } else {
        // Definitely not executed (validation, insufficient funds, permission...): nothing was
        // created, the same order_id can be used again once the cause is fixed.
        fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
        exit(1);
    }
}

report($payout);

function report(Payout $payout): void
{
    printf("Payout %s for order %s: %s%s\n", $payout->id, $payout->orderId, $payout->status->value, $payout->isFinal ? ' (final)' : '');
    if ($payout->failureCode !== null) {
        printf("Failure code: %s\n", $payout->failureCode);
    }
    echo "Follow the payout with payout.* webhooks or payouts->get().\n";
}
