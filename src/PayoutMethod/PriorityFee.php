<?php

declare(strict_types=1);

namespace Enigmoss\PayoutMethod;

use Enigmoss\Internal\Payload;
use Enigmoss\Payout\PayoutPriority;

final class PriorityFee
{
    /**
     * @internal
     */
    public function __construct(
        public readonly PayoutPriority $priority,
        public readonly string $fee,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self($p->enum('priority', PayoutPriority::class, PayoutPriority::Unknown), $p->decimal('fee'));
    }
}
