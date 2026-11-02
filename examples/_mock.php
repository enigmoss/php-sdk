<?php

declare(strict_types=1);

// Offline scenarios of the examples: ENIGMOSS_EXAMPLE_MOCK=<scenario> php examples/<example>.php

use Enigmoss\Exception\SendPhase;
use Enigmoss\Exception\TransportException;
use Enigmoss\Testing\Fixtures;
use Enigmoss\Testing\MockTransport;

$emptyPage = ['cursor' => ['next' => null, 'prev' => null, 'per_page' => 1, 'has_more' => false]];
$inProgress = static function (MockTransport $t): void {
    for ($i = 0; $i < 3; ++$i) {
        $t->queueError(409, 'conflict', 'idempotency.in_progress', 'A request with this Idempotency-Key is in progress.', retryable: true, retryAfter: 5);
    }
};
$timeouts = static function (MockTransport $t): void {
    for ($i = 0; $i < 3; ++$i) {
        $t->queue(new TransportException('Operation timed out after the request was sent', SendPhase::Sent, true));
    }
};
$orderTaken = static fn(MockTransport $t) => $t->queueError(409, 'conflict', 'resource.conflict', 'The order_id is already used.', details: ['id' => '9b2d4f6a-1c3e-4a5b-8d7f-0e1a2b3c4d5e', 'api_version' => 'v4']);

return [
    'create-payment' => [
        'happy' => static fn(MockTransport $t) => $t->queueData(Fixtures::payment(), 201),
        'order_taken' => static function (MockTransport $t): void {
            $t->queueError(409, 'conflict', 'resource.conflict', 'The order_id is already used.', details: ['id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'api_version' => 'v4']);
            $t->queueData(Fixtures::payment());
        },
        'in_progress' => static function (MockTransport $t) use ($inProgress, $emptyPage): void {
            $inProgress($t);
            $t->queueData([], meta: $emptyPage);
        },
    ],
    'payout-flow' => [
        'happy' => static function (MockTransport $t): void {
            $t->queueData(Fixtures::payoutQuote());
            $t->queueData(Fixtures::payout(), 201);
        },
        'order_taken' => static function (MockTransport $t) use ($orderTaken): void {
            $t->queueData(Fixtures::payoutQuote());
            $orderTaken($t);
            $t->queueData(Fixtures::payout(['status' => 'completed', 'is_final' => true]));
        },
        'unknown_found' => static function (MockTransport $t) use ($timeouts): void {
            $t->queueData(Fixtures::payoutQuote());
            $timeouts($t);
            $t->queueData([Fixtures::payout()], meta: ['cursor' => ['next' => null, 'prev' => null, 'per_page' => 1, 'has_more' => false]]);
        },
        'unknown_pending' => static function (MockTransport $t) use ($timeouts, $emptyPage): void {
            $t->queueData(Fixtures::payoutQuote());
            $timeouts($t);
            $t->queueData([], meta: $emptyPage);
        },
        'in_progress' => static function (MockTransport $t) use ($inProgress, $emptyPage): void {
            $t->queueData(Fixtures::payoutQuote());
            $inProgress($t);
            $t->queueData([], meta: $emptyPage);
        },
    ],
    'refund' => [
        'happy' => static fn(MockTransport $t) => $t->queueData(Fixtures::refund(), 201),
        'order_taken' => static function (MockTransport $t): void {
            $t->queueError(409, 'conflict', 'resource.conflict', 'The order_id is already used.', details: ['id' => '01970000-0000-7000-8000-000000000001', 'api_version' => 'v4']);
            $t->queueData(Fixtures::refund());
        },
        'in_progress' => static function (MockTransport $t) use ($inProgress, $emptyPage): void {
            $inProgress($t);
            $t->queueData([], meta: $emptyPage);
        },
    ],
    'static-wallet' => [
        'happy' => static function (MockTransport $t) use ($emptyPage): void {
            $t->queueData(Fixtures::wallet(), 201);
            $t->queueData([Fixtures::paidPayment(['order_id' => null, 'wallet_id' => '0f8fad5b-d9cb-469f-a165-70867728950e'])], meta: $emptyPage);
        },
    ],
    'auto-pagination' => [
        'happy' => static function (MockTransport $t): void {
            $t->queueData([Fixtures::paidPayment(['order_id' => 'a']), Fixtures::paidPayment(['order_id' => 'b'])], meta: ['cursor' => ['next' => 'c2', 'prev' => null, 'per_page' => 2, 'has_more' => true]]);
            $t->queueData([Fixtures::paidPayment(['order_id' => 'c'])], meta: ['cursor' => ['next' => null, 'prev' => 'c1', 'per_page' => 2, 'has_more' => false]]);
            $t->queueData([Fixtures::paidPayment(['order_id' => 'a']), Fixtures::paidPayment(['order_id' => 'b'])], meta: ['cursor' => ['next' => 'c2', 'prev' => null, 'per_page' => 2, 'has_more' => true]]);
            $t->queueData([Fixtures::paidPayment(['order_id' => 'c'])], meta: ['cursor' => ['next' => null, 'prev' => 'c1', 'per_page' => 2, 'has_more' => false]]);
            $t->queueData([Fixtures::paidPayment()], meta: ['cursor' => ['next' => null, 'prev' => null, 'per_page' => 1, 'has_more' => false]]);
        },
    ],
];
