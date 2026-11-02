<?php

declare(strict_types=1);

namespace Enigmoss\Transfer;

use Enigmoss\Internal\Payload;

/**
 * A transfer from the business balance to the owner's personal account. Synchronous: a returned
 * transfer means the money has moved.
 */
final class Transfer
{
    /**
     * @internal
     */
    public function __construct(
        public readonly string $id,
        public readonly string $orderId,
        public readonly string $amount,
        public readonly string $currencyCode,
        /** The debiting movement in transactions. */
        public readonly ?string $transactionId,
        public readonly \DateTimeImmutable $createdAt,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            $p->string('id'),
            $p->string('order_id'),
            $p->decimal('amount'),
            $p->string('currency_code'),
            $p->nullableString('transaction_id'),
            $p->dateTime('created_at'),
        );
    }
}
