<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Enigmoss\Exception\EnigmossException;
use Enigmoss\Payment\ListPaymentsQuery;
use Enigmoss\Wallet\CreateWalletRequest;

/**
 * Creates a static wallet for a customer and lists the deposits made to it.
 *
 *   php examples/static-wallet.php [customer-id]
 */
$enigmoss = exampleClient('static-wallet');

// order_id is your ID of the wallet owner. One order_id = one wallet, forever.
$customerId = $argv[1] ?? 'customer-' . bin2hex(random_bytes(3));

try {
    $wallet = $enigmoss->wallets->create(new CreateWalletRequest(
        currencyCode: 'USDT',
        networkCode: 'tron',
        orderId: $customerId,
    ));

    printf("Wallet %s (%s)\n", $wallet->id, $wallet->status->value);
    printf("Deposit address: %s (%s on %s)\n", $wallet->address, $wallet->currencyCode, $wallet->networkCode);
    printf("Show the customer: %s\n\n", $wallet->paymentUrl);

    // Every deposit is a Payment with walletId set. findByOrderId() does not find deposits:
    // list by walletId instead.
    $count = 0;
    foreach ($enigmoss->payments->iterate(new ListPaymentsQuery(walletId: $wallet->id)) as $deposit) {
        ++$count;
        printf("Deposit %s: %s %s, status %s\n", $deposit->id, $deposit->paid->amount ?? '0', $deposit->currencyCode, $deposit->status->value);
    }
    echo $count === 0 ? "No deposits yet.\n" : '';
} catch (EnigmossException $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
