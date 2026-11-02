<?php

declare(strict_types=1);

namespace Enigmoss\Tests\Unit\Webhook;

use Enigmoss\Event\PaymentEvent;
use Enigmoss\Event\TestEvent;
use Enigmoss\Event\UnknownEvent;
use Enigmoss\Exception\UnexpectedResponseException;
use Enigmoss\Exception\WebhookFailureReason;
use Enigmoss\Exception\WebhookVerificationException;
use Enigmoss\Payment\PaymentStatus;
use Enigmoss\Secret;
use Enigmoss\Testing\Fixtures;
use Enigmoss\Testing\WebhookSimulator;
use Enigmoss\Tests\Support\FakeClock;
use Enigmoss\Tests\Support\SignatureVectors;
use Enigmoss\Webhook\WebhookVerifier;
use PHPUnit\Framework\TestCase;

final class WebhookVerifierTest extends TestCase
{
    private const PROJECT = 'd6a7f0c2-6b1e-4b9a-9f3e-2c4d5e6f7a8b';
    private const VECTOR_BODY = '{"data": null, "event": "webhook.test", "event_id": "019b7710-0000-7000-8000-000000000001", "created_at": "2026-01-01T00:00:00Z"}';

    public function testNormativeVectorIsAccepted(): void
    {
        $vector = SignatureVectors::webhook();
        self::assertSame(self::PROJECT, $vector['project']);

        // Header names in any case, signature in upper case: both are accepted.
        $event = $this->verifier()->constructEvent($vector['body'], [
            'x-project-uuid' => $vector['project'],
            'X-Timestamp' => $vector['timestamp'],
            'X-SIGNATURE' => strtoupper($vector['signature']),
        ]);

        self::assertInstanceOf(TestEvent::class, $event);
        self::assertSame('019b7710-0000-7000-8000-000000000001', $event->id);
        self::assertNull($event->data());
    }

    public function testProjectIsComparedCaseInsensitivelyButSignedAsSent(): void
    {
        $webhook = WebhookSimulator::sign(self::VECTOR_BODY, strtoupper(self::PROJECT), new Secret('test-payment-secret'), 1767225600);

        $this->verifier()->verify($webhook->body, $webhook->headers);
        $this->addToAssertionCount(1);
    }

    public function testStaleTimestampIsRejected(): void
    {
        $webhook = WebhookSimulator::sign(self::VECTOR_BODY, self::PROJECT, new Secret('test-payment-secret'), 1767225600 - 301);

        $this->assertReason(WebhookFailureReason::TimestampOutOfTolerance, fn() => $this->verifier()->verify($webhook->body, $webhook->headers));
    }

    public function testOtherProjectIsRejected(): void
    {
        $webhook = WebhookSimulator::sign(self::VECTOR_BODY, Fixtures::PROJECT_ID, new Secret('test-payment-secret'), 1767225600);

        $this->assertReason(WebhookFailureReason::ProjectMismatch, fn() => $this->verifier()->verify($webhook->body, $webhook->headers));
    }

    public function testTamperedBodyIsRejected(): void
    {
        $webhook = WebhookSimulator::sign(self::VECTOR_BODY, self::PROJECT, new Secret('test-payment-secret'), 1767225600);

        $this->assertReason(WebhookFailureReason::SignatureMismatch, fn() => $this->verifier()->verify($webhook->body . ' ', $webhook->headers));
    }

    public function testMissingHeadersAreRejected(): void
    {
        $this->assertReason(WebhookFailureReason::MissingHeader, fn() => $this->verifier()->verify(self::VECTOR_BODY, []));
    }

    public function testPreviousKeyIsAcceptedDuringRotation(): void
    {
        $webhook = WebhookSimulator::sign(self::VECTOR_BODY, self::PROJECT, new Secret('old-secret'), 1767225600);
        $verifier = new WebhookVerifier(self::PROJECT, [new Secret('test-payment-secret'), new Secret('old-secret')], 300, new FakeClock());

        $verifier->verify($webhook->body, $webhook->headers);
        $this->addToAssertionCount(1);
    }

    public function testTypedEventIsHydratedLazily(): void
    {
        $paid = WebhookSimulator::sign(Fixtures::webhook('payment.paid', Fixtures::paidPayment()), self::PROJECT, new Secret('test-payment-secret'), 1767225600);
        $event = $this->verifier()->constructEvent($paid->body, $paid->headers);

        self::assertInstanceOf(PaymentEvent::class, $event);
        self::assertSame(PaymentStatus::Paid, $event->status());
        self::assertSame('10.50', $event->payment()->paid->amount);

        $broken = WebhookSimulator::sign(Fixtures::webhook('payment.paid', ['id' => 42]), self::PROJECT, new Secret('test-payment-secret'), 1767225600);
        $event = $this->verifier()->constructEvent($broken->body, $broken->headers);
        self::assertInstanceOf(PaymentEvent::class, $event);
        $this->expectException(UnexpectedResponseException::class);
        $event->payment();
    }

    public function testUnknownEventTypeNeverThrows(): void
    {
        $webhook = WebhookSimulator::sign(Fixtures::webhook('subscription.renewed', ['id' => 'x']), self::PROJECT, new Secret('test-payment-secret'), 1767225600);

        $event = $this->verifier()->constructEvent($webhook->body, $webhook->headers);

        self::assertInstanceOf(UnknownEvent::class, $event);
        self::assertSame('subscription.renewed', $event->type);
        self::assertSame(['id' => 'x'], $event->data());
    }

    private function verifier(): WebhookVerifier
    {
        return new WebhookVerifier(self::PROJECT, new Secret('test-payment-secret'), 300, new FakeClock());
    }

    private function assertReason(WebhookFailureReason $reason, \Closure $call): void
    {
        try {
            $call();
            self::fail('Expected WebhookVerificationException.');
        } catch (WebhookVerificationException $e) {
            self::assertSame($reason, $e->reason);
        }
    }
}
