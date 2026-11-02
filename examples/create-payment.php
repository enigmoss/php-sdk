<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Enigmoss\Exception\ConflictException;
use Enigmoss\Exception\EnigmossException;
use Enigmoss\Exception\ValidationException;
use Enigmoss\Payment\CreatePaymentRequest;

/**
 * Creates an invoice and prints the payment page URL.
 *
 *   php examples/create-payment.php [order-id]
 *   ENIGMOSS_EXAMPLE_MOCK=happy|order_taken|in_progress php examples/create-payment.php   (offline)
 */
$enigmoss = exampleClient('create-payment');

// One order_id = one invoice, forever. Running the script again with the same id returns the
// same invoice instead of creating a second one.
$orderId = $argv[1] ?? 'demo-' . bin2hex(random_bytes(4));

try {
    $payment = $enigmoss->payments->create(new CreatePaymentRequest(
        amount: '10.50',                 // always a decimal string, never a float
        currencyCode: 'USDT',
        orderId: $orderId,
        description: 'Demo order',
        metadata: ['customer_id' => 42],
    ));
} catch (EnigmossException $e) {
    // 1. Unknown outcome FIRST: it also covers a request still in progress, which is a
    //    ConflictException subclass. The invoice may exist - never switch to a new order_id.
    if ($e->outcomeUnknown()) {
        $existing = $enigmoss->payments->findByOrderId($orderId);
        if ($existing === null) {
            fwrite(STDERR, "Outcome unknown and not visible yet: run again later with the same order_id {$orderId}.\n");
            exit(3);
        }
        $payment = $existing;
    } elseif ($e instanceof ConflictException && $e->isOrderIdTaken()) {
        // 2. The order_id already has an invoice (created by an earlier run): use it.
        $payment = $enigmoss->payments->get((string) $e->existingResourceId());
        echo "order_id already used, showing the existing invoice.\n";
    } elseif ($e instanceof ValidationException) {
        // 3. Definitely not executed: fix the request.
        foreach ($e->violations as $violation) {
            fwrite(STDERR, sprintf("%s: %s\n", $violation->field ?? '(request)', $violation->message));
        }
        exit(1);
    } else {
        fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
        exit(1);
    }
}

printf("Invoice %s is %s\n", $payment->id, $payment->status->value);
if ($payment->paymentUrl === null) {
    fwrite(STDERR, "The invoice has no payment page URL.\n");
    exit(1);
}
printf("Redirect the customer to: %s\n", $payment->paymentUrl);
