<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Enigmoss\Exception\EnigmossException;
use Enigmoss\Payment\ListPaymentsQuery;
use Enigmoss\Payment\PaymentStatus;

/**
 * Walks paid invoices of the last 30 days: lazily with iterate(), then page by page with cursors.
 */
$enigmoss = exampleClient('auto-pagination');

$query = new ListPaymentsQuery(
    status: PaymentStatus::Paid,
    createdFrom: new DateTimeImmutable('-30 days', new DateTimeZone('UTC')),
    limit: 50,
);

try {
    // 1. Automatic: pages are fetched only as you consume them.
    $total = 0;
    foreach ($enigmoss->payments->iterate($query) as $payment) {
        ++$total;
        printf("%s  %s  %s %s\n", $payment->createdAt?->format('Y-m-d H:i') ?? '-', $payment->orderId ?? '(deposit)', $payment->amount ?? '?', $payment->currencyCode);
        if ($total >= 200) {
            echo "Stopping after 200 invoices.\n";
            break;
        }
    }
    echo "Invoices seen: {$total}\n\n";

    // 2. Manual: one page at a time, resuming from an opaque cursor.
    $page = $enigmoss->payments->list($query);
    printf("First page: %d items, hasMore=%s\n", count($page), $page->hasMore ? 'yes' : 'no');
    if ($page->hasMore && $page->nextCursor !== null) {
        $next = $enigmoss->payments->list($query->withCursor($page->nextCursor));
        printf("Second page: %d items\n", count($next));
    }

    // 3. Reconcile one order.
    $one = $enigmoss->payments->findByOrderId('order-42');
    echo $one === null ? "order-42 not found\n" : "order-42 is {$one->status->value}\n";
} catch (EnigmossException $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
