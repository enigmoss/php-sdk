<?php

declare(strict_types=1);

namespace Enigmoss\Event;

use Enigmoss\Refund\Refund;
use Enigmoss\Refund\RefundStatus;

/**
 * refund.<status>: a refund changed status.
 */
final class RefundEvent extends WebhookEvent
{
    private ?Refund $refund = null;

    /**
     * @throws \Enigmoss\Exception\UnexpectedResponseException When the snapshot does not match this SDK version.
     */
    public function refund(): Refund
    {
        return $this->refund ??= Refund::fromPayload($this->payload());
    }

    public function status(): RefundStatus
    {
        return RefundStatus::tryFrom($this->statusName()) ?? RefundStatus::Unknown;
    }
}
