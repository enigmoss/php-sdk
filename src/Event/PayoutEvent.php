<?php

declare(strict_types=1);

namespace Enigmoss\Event;

use Enigmoss\Payout\Payout;
use Enigmoss\Payout\PayoutStatus;

/**
 * payout.<status>: a payout created through the API changed status.
 */
final class PayoutEvent extends WebhookEvent
{
    private ?Payout $payout = null;

    /**
     * @throws \Enigmoss\Exception\UnexpectedResponseException When the snapshot does not match this SDK version.
     */
    public function payout(): Payout
    {
        return $this->payout ??= Payout::fromPayload($this->payload());
    }

    public function status(): PayoutStatus
    {
        return PayoutStatus::tryFrom($this->statusName()) ?? PayoutStatus::Unknown;
    }
}
