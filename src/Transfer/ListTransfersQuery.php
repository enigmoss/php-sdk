<?php

declare(strict_types=1);

namespace Enigmoss\Transfer;

use Enigmoss\Internal\Assert;

final class ListTransfersQuery
{
    /**
     * @param int|null $limit Page size, 1-50 (default 20).
     */
    public function __construct(
        public readonly ?string $orderId = null,
        public readonly ?int $limit = null,
        public readonly ?string $cursor = null,
    ) {
        if ($orderId !== null) {
            Assert::orderId($orderId);
        }
        Assert::range($limit, 'limit', 1, 50);
    }

    public function withCursor(?string $cursor): self
    {
        return new self($this->orderId, $this->limit, $cursor);
    }

    /**
     * @internal
     *
     * @return array<string, string|int|bool|null>
     */
    public function toQuery(): array
    {
        return ['order_id' => $this->orderId, 'limit' => $this->limit, 'cursor' => $this->cursor];
    }
}
