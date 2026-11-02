<?php

declare(strict_types=1);

namespace Enigmoss\Payment;

use Enigmoss\Internal\Assert;
use Enigmoss\Internal\Query;

/**
 * Filters of the payment list. All filters are optional; results are newest first.
 */
final class ListPaymentsQuery
{
    /**
     * @param int|null $limit Page size, 1-50 (default 20).
     */
    public function __construct(
        public readonly ?string $orderId = null,
        public readonly ?string $walletId = null,
        public readonly ?PaymentStatus $status = null,
        public readonly ?\DateTimeInterface $createdFrom = null,
        public readonly ?\DateTimeInterface $createdTo = null,
        public readonly ?int $limit = null,
        public readonly ?string $cursor = null,
    ) {
        if ($orderId !== null) {
            Assert::orderId($orderId);
        }
        if ($walletId !== null) {
            Assert::uuid($walletId, 'walletId');
        }
        Assert::knownEnum($status, 'status');
        Assert::range($limit, 'limit', 1, 50);
    }

    public function withCursor(?string $cursor): self
    {
        return new self($this->orderId, $this->walletId, $this->status, $this->createdFrom, $this->createdTo, $this->limit, $cursor);
    }

    /**
     * @internal
     *
     * @return array<string, string|int|bool|null>
     */
    public function toQuery(): array
    {
        return [
            'order_id' => $this->orderId,
            'wallet_id' => $this->walletId,
            'status' => $this->status?->value,
            'created_from' => Query::dateTime($this->createdFrom),
            'created_to' => Query::dateTime($this->createdTo),
            'limit' => $this->limit,
            'cursor' => $this->cursor,
        ];
    }
}
