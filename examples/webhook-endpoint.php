<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Enigmoss\Event\PaymentEvent;
use Enigmoss\Event\PayoutEvent;
use Enigmoss\Event\RefundEvent;
use Enigmoss\Event\TestEvent;
use Enigmoss\Exception\WebhookVerificationException;
use Enigmoss\Secret;
use Enigmoss\Webhook\PdoWebhookDeduplicator;
use Enigmoss\Webhook\WebhookVerifier;

/**
 * A plain-PHP webhook endpoint. Try it locally:
 *
 *   ENIGMOSS_PROJECT_ID=... ENIGMOSS_PAYMENT_KEY=... php -S localhost:8000 examples/webhook-endpoint.php
 *
 * Deduplication uses your database (ENIGMOSS_WEBHOOK_DSN, a local SQLite file by default):
 *
 *   CREATE TABLE enigmoss_webhook_events (event_id VARCHAR(64) PRIMARY KEY, claimed_at TIMESTAMP NOT NULL);
 *
 * Webhooks are signed with the PAYMENT key. Verify the RAW body, answer 2xx within 15 seconds,
 * and do the real work in a queue.
 */
$projectId = getenv('ENIGMOSS_PROJECT_ID');
$paymentKey = getenv('ENIGMOSS_PAYMENT_KEY');
if (!is_string($projectId) || $projectId === '' || !is_string($paymentKey) || $paymentKey === '') {
    http_response_code(500);
    echo 'Set ENIGMOSS_PROJECT_ID and ENIGMOSS_PAYMENT_KEY.';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

// During a key rotation pass both keys: [new Secret($new), new Secret($old)].
$verifier = new WebhookVerifier($projectId, new Secret($paymentKey));

// The exact bytes of the body: never json_decode() and re-encode before verifying.
$rawBody = (string) file_get_contents('php://input');

try {
    $event = $verifier->constructEvent($rawBody, WebhookVerifier::headersFromGlobals());
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_webhook', 'reason' => $e->reason->value]);
    exit;
}

// Deduplicate by $event->id: retries and redeliveries carry the same id. claim() is atomic, so
// two simultaneous deliveries cannot both pass.
$dsn = getenv('ENIGMOSS_WEBHOOK_DSN');
$pdo = new PDO(is_string($dsn) && $dsn !== '' ? $dsn : 'sqlite:' . sys_get_temp_dir() . '/enigmoss-webhooks.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE IF NOT EXISTS enigmoss_webhook_events (event_id VARCHAR(64) PRIMARY KEY, claimed_at TIMESTAMP NOT NULL)');
$deduplicator = new PdoWebhookDeduplicator($pdo);
if ($event->id !== '' && !$deduplicator->claim($event->id)) {
    http_response_code(200);
    echo 'duplicate';
    exit;
}

try {
    handle($event);
} catch (Throwable $e) {
    // Not handled: release the claim and answer 500, so the API delivers the webhook again.
    if ($event->id !== '') {
        $deduplicator->release($event->id);
    }
    http_response_code(500);
    exit;
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'accepted']);

/**
 * Keep this fast: enqueue the real work instead of doing it here (the API waits 15 seconds).
 */
function handle(Enigmoss\Event\WebhookEvent $event): void
{
    if ($event instanceof PaymentEvent) {
        $payment = $event->payment();
        if ($payment->isSuccessful()) {
            // A deposit to a static wallet has walletId and no orderId: credit the wallet owner.
            $target = $payment->walletId !== null ? 'wallet ' . $payment->walletId : 'order ' . $payment->orderId;
            // enqueue('fulfil', $payment->id);
            error_log(sprintf('Paid: %s (%s %s)', $target, $payment->paid->amount ?? '0', $payment->payer->currencyCode ?? $payment->currencyCode));
        }
    } elseif ($event instanceof PayoutEvent) {
        error_log(sprintf('Payout %s -> %s', $event->payout()->orderId, $event->status()->value));
    } elseif ($event instanceof RefundEvent) {
        error_log(sprintf('Refund %s -> %s', $event->refund()->id, $event->status()->value));
    } elseif ($event instanceof TestEvent) {
        error_log('Test webhook received');
    } else {
        // UnknownEvent: a type this SDK version does not know. Acknowledge it.
        error_log('Unknown webhook type ' . $event->type);
    }
}
