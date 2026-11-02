<?php

declare(strict_types=1);

namespace Enigmoss\Refund;

use Enigmoss\Internal\Assert;
use Enigmoss\Internal\Query;

final class ListRefundsQuery
{
    /**
     * @param int|null $limit Page size, 1-100 (default 20).
     */
    public function __construct(
        public readonly ?string $orderId = null,
        public readonly ?RefundSource $source = null,
        public readonly ?RefundStatus $status = null,
        public readonly ?string $paymentId = null,
        public readonly ?string $walletId = null,
        public readonly ?\DateTimeInterface $createdFrom = null,
        public readonly ?\DateTimeInterface $createdTo = null,
        public readonly ?int $limit = null,
        public readonly ?string $cursor = null,
    ) {
        if ($orderId !== null) {
            Assert::orderId($orderId);
        }
        if ($paymentId !== null) {
            Assert::uuid($paymentId, 'paymentId');
        }
        if ($walletId !== null) {
            Assert::uuid($walletId, 'walletId');
        }
        Assert::knownEnum($source, 'source');
        Assert::knownEnum($status, 'status');
        Assert::range($limit, 'limit', 1, 100);
    }

    public function withCursor(?string $cursor): self
    {
        return new self($this->orderId, $this->source, $this->status, $this->paymentId, $this->walletId, $this->createdFrom, $this->createdTo, $this->limit, $cursor);
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
            'source' => $this->source?->value,
            'status' => $this->status?->value,
            'payment_id' => $this->paymentId,
            'wallet_id' => $this->walletId,
            'created_from' => Query::dateTime($this->createdFrom),
            'created_to' => Query::dateTime($this->createdTo),
            'limit' => $this->limit,
            'cursor' => $this->cursor,
        ];
    }
}
