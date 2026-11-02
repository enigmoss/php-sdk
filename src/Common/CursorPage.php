<?php

declare(strict_types=1);

namespace Enigmoss\Common;

use Enigmoss\Http\ResponseInfo;
use Enigmoss\Internal\ApiResult;
use Enigmoss\Internal\Payload;

/**
 * One page of a cursor-paginated list, newest first.
 *
 *     $page = $enigmoss->payments->list(new ListPaymentsQuery(limit: 50));
 *     foreach ($page as $payment) { ... }
 *     if ($page->hasMore) { $next = $enigmoss->payments->list($query->withCursor($page->nextCursor)); }
 *
 * To walk every page use the service's iterate() method instead.
 *
 * @template T
 *
 * @implements \IteratorAggregate<int, T>
 */
final class CursorPage implements \IteratorAggregate, \Countable
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public readonly array $items,
        /** Opaque cursor of the next (older) page; null on the last page. */
        public readonly ?string $nextCursor,
        /** Opaque cursor of the previous (newer) page; null on the first page. */
        public readonly ?string $prevCursor,
        public readonly int $perPage,
        public readonly bool $hasMore,
        public readonly ?ResponseInfo $info = null,
    ) {}

    /**
     * @internal
     *
     * @template TItem
     *
     * @param \Closure(Payload): TItem $map
     *
     * @return self<TItem>
     */
    public static function fromResult(ApiResult $result, \Closure $map): self
    {
        $items = array_map($map, $result->payloads());
        $cursor = \is_array($result->meta['cursor'] ?? null) ? $result->meta['cursor'] : [];

        return new self(
            $items,
            \is_string($cursor['next'] ?? null) ? $cursor['next'] : null,
            \is_string($cursor['prev'] ?? null) ? $cursor['prev'] : null,
            \is_int($cursor['per_page'] ?? null) ? $cursor['per_page'] : \count($items),
            ($cursor['has_more'] ?? false) === true,
            $result->info,
        );
    }

    /**
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }

    /**
     * @return T|null
     */
    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }
}
