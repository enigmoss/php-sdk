<?php

declare(strict_types=1);

namespace Enigmoss\Event;

use Enigmoss\Payment\Payment;
use Enigmoss\Payment\PaymentStatus;

/**
 * payment.<status>: an invoice or a static wallet deposit changed status.
 */
final class PaymentEvent extends WebhookEvent
{
    private ?Payment $payment = null;

    /**
     * The payment snapshot at the moment of the event.
     *
     * @throws \Enigmoss\Exception\UnexpectedResponseException When the snapshot does not match this SDK version.
     */
    public function payment(): Payment
    {
        return $this->payment ??= Payment::fromPayload($this->payload());
    }

    public function status(): PaymentStatus
    {
        return PaymentStatus::tryFrom($this->statusName()) ?? PaymentStatus::Unknown;
    }
}
