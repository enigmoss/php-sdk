<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

use Enigmoss\Common\CursorPage;

/**
 * @internal
 */
final class Paginator
{
    /** Long scans hit the per-IP rate limit; wait out a longer Retry-After than single calls do. */
    public const ITERATE_MAX_RETRY_DELAY = 60.0;

    /**
     * Lazily walks all pages, fetching the next one only when the previous is consumed.
     *
     * @template T
     *
     * @param \Closure(?string): CursorPage<T> $fetch Fetches the page at a cursor (null = first page).
     *
     * @return \Generator<int, T>
     */
    public static function iterate(\Closure $fetch, ?string $cursor = null): \Generator
    {
        do {
            $page = $fetch($cursor);
            foreach ($page->items as $item) {
                yield $item;
            }
            $cursor = $page->nextCursor;
        } while ($page->hasMore && $cursor !== null);
    }
}
