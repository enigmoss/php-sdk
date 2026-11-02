<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Enigmoss\Exception\BusinessRuleException;
use Enigmoss\Exception\ConflictException;
use Enigmoss\Exception\EnigmossException;
use Enigmoss\Exception\RateLimitException;
use Enigmoss\Refund\CreatePaymentRefundRequest;
use Enigmoss\Refund\Refund;

/**
 * Refunds a paid invoice (fully, or partly when an amount is given). Requires the payout key.
 *
 *   php examples/refund.php <payment-id> <customer-address> [amount] [order-id]
 *   ENIGMOSS_EXAMPLE_MOCK=happy|order_taken|in_progress php examples/refund.php 7c9e6679-7425-40de-944b-e07fc1f90ae7 TCustomerabcdefghijklmnopqrstuvwxy
 *
 * Store the order_id of the refund in your database BEFORE calling the API and reuse it on
 * every retry: one order_id = one refund, forever.
 */
if (!isset($argv[1], $argv[2])) {
    fwrite(STDERR, "Usage: php examples/refund.php <payment-id> <customer-address> [amount] [order-id]\n");
    exit(2);
}

[, $paymentId, $address] = $argv;
$amount = $argv[3] ?? null;
$orderId = $argv[4] ?? 'refund-' . bin2hex(random_bytes(4));

$enigmoss = exampleClient('refund', paymentKey: false, payoutKey: true);

try {
    $refund = $enigmoss->refunds->createForPayment($paymentId, new CreatePaymentRefundRequest(
        orderId: $orderId,
        address: $address,
        amount: $amount,   // null refunds everything that was paid
    ));
} catch (EnigmossException $e) {
    if ($e->outcomeUnknown()) {
        // The refund may exist or still be in progress. NEVER create a new order_id: that is how
        // a refund gets paid twice. Reconcile now, or later with the same order_id.
        $existing = $enigmoss->refunds->findByOrderId($orderId);
        if ($existing === null) {
            fwrite(STDERR, 'Outcome unknown (' . $e->getMessage() . ").\nKeep the refund pending and reconcile later with order_id {$orderId}.\n");
            exit(3);
        }
        $refund = $existing;
    } elseif ($e instanceof ConflictException && $e->isOrderIdTaken()) {
        $refund = $enigmoss->refunds->get((string) $e->existingResourceId());
        echo "order_id already used, showing the existing refund.\n";
    } elseif ($e instanceof ConflictException) {
        // The payment cannot be refunded in its current state, or a refund is unfinished.
        fwrite(STDERR, 'Cannot refund now: ' . $e->getMessage() . "\n");
        exit(1);
    } elseif ($e instanceof RateLimitException) {
        // Refunds are limited to 1 request per minute per project. Retry with the SAME order_id.
        fwrite(STDERR, 'Rate limited, retry in ' . ($e->retryAfter ?? 60) . " s with order_id {$orderId}.\n");
        exit(1);
    } elseif ($e instanceof BusinessRuleException) {
        // Rejected, nothing was created: the same order_id can be used again after fixing the cause.
        fwrite(STDERR, 'Refund rejected: ' . $e->getMessage() . "\n");
        exit(1);
    } else {
        fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
        exit(1);
    }
}

report($refund);

function report(Refund $refund): void
{
    printf("Refund %s is %s (%s %s to %s)\n", $refund->id, $refund->status->value, $refund->amount ?? 'full amount', $refund->currencyCode, $refund->address);
    echo "Follow it with refund.* webhooks or refunds->get().\n";
}
