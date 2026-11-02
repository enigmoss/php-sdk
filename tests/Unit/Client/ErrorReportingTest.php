<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Unit\Client;

use Enigmoss\Exception\ConflictException;
use Enigmoss\Exception\IdempotencyException;
use Enigmoss\Exception\PermissionDeniedException;
use Enigmoss\Payment\Payment;
use Enigmoss\Payout\CreatePayoutRequest;
use Enigmoss\Testing\Fixtures;
use Enigmoss\Testing\MockTransport;
use Enigmoss\Tests\Support\ClientFactory;
use PHPUnit\Framework\TestCase;

final class ErrorReportingTest extends TestCase
{
    public function testOrderIdTakenIsTellableFromOtherConflicts(): void
    {
        $taken = $this->conflict(['id' => 'p-1', 'api_version' => 'v4']);
        $state = $this->conflict(['id' => 'p-1', 'status' => 'paid']);

        self::assertTrue($taken->isOrderIdTaken());
        self::assertSame('p-1', $taken->existingResourceId());
        self::assertFalse($state->isOrderIdTaken());
    }

    public function testInProgressIsNeverAnOrderIdConflict(): void
    {
        $transport = (new MockTransport())
            ->queueError(409, 'conflict', 'idempotency.in_progress', 'Request in progress.', retryable: true, retryAfter: 1)
            ->queueError(409, 'conflict', 'idempotency.in_progress', 'Request in progress.', retryable: true, retryAfter: 1)
            ->queueError(409, 'conflict', 'idempotency.in_progress', 'Request in progress.', retryable: true, retryAfter: 1);

        try {
            ClientFactory::make($transport)->payouts->create($this->payout());
            self::fail('Expected IdempotencyException.');
        } catch (IdempotencyException $e) {
            self::assertFalse($e->isOrderIdTaken());
            self::assertTrue($e->outcomeUnknown());
        }
    }

    public function testMessageCarriesCodeRequestIdAndHint(): void
    {
        $transport = (new MockTransport())->queueError(403, 'authorization_error', 'api_permission.denied', 'Permission denied.', details: ['permission' => 'payout_create']);

        try {
            ClientFactory::make($transport)->payouts->create($this->payout());
            self::fail('Expected PermissionDeniedException.');
        } catch (PermissionDeniedException $e) {
            self::assertSame('Permission denied.', $e->serverMessage);
            self::assertStringContainsString('[api_permission.denied, request 01JTESTERROR00000000000000]', $e->getMessage());
            self::assertStringContainsString('"payout_create"', $e->getMessage());
        }
    }

    public function testSuccessfulPaymentNeedsPaidAndFinal(): void
    {
        self::assertTrue(Payment::fromArray(Fixtures::paidPayment())->isSuccessful());
        self::assertFalse(Payment::fromArray(Fixtures::paidPayment(['is_final' => false]))->isSuccessful());
        self::assertFalse(Payment::fromArray(Fixtures::payment())->isSuccessful());
    }

    /**
     * @param array<string, mixed> $details
     */
    private function conflict(array $details): ConflictException
    {
        $transport = (new MockTransport())->queueError(409, 'conflict', 'resource.conflict', 'Conflict.', details: $details);

        try {
            ClientFactory::make($transport)->payouts->create($this->payout());
        } catch (ConflictException $e) {
            return $e;
        }

        self::fail('Expected ConflictException.');
    }

    private function payout(): CreatePayoutRequest
    {
        return new CreatePayoutRequest('withdrawal-42', '100.00', 'USDT', 'TRecipientabcdefghijklmnopqrstuvwx', 'tron');
    }
}
