<?php

declare(strict_types=1);

namespace Enigmoss\Transaction;

use Enigmoss\Exception\InvalidArgumentException;
use Enigmoss\Internal\Assert;
use Enigmoss\Internal\Query;

final class ListTransactionsQuery
{
    /**
     * @param int|null $limit Page size, 1-50 (default 20).
     */
    public function __construct(
        public readonly ?TransactionType $type = null,
        public readonly ?\DateTimeInterface $createdFrom = null,
        public readonly ?\DateTimeInterface $createdTo = null,
        public readonly ?int $limit = null,
        public readonly ?string $cursor = null,
    ) {
        Assert::knownEnum($type, 'type');
        if ($type === TransactionType::Other) {
            throw new InvalidArgumentException('TransactionType::Other cannot be used as a filter.');
        }
        Assert::range($limit, 'limit', 1, 50);
    }

    public function withCursor(?string $cursor): self
    {
        return new self($this->type, $this->createdFrom, $this->createdTo, $this->limit, $cursor);
    }

    /**
     * @internal
     *
     * @return array<string, string|int|bool|null>
     */
    public function toQuery(): array
    {
        return [
            'type' => $this->type?->value,
            'created_from' => Query::dateTime($this->createdFrom),
            'created_to' => Query::dateTime($this->createdTo),
            'limit' => $this->limit,
            'cursor' => $this->cursor,
        ];
    }
}
