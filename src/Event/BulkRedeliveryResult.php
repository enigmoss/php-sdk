<?php

declare(strict_types=1);

namespace Enigmoss\Event;

use Enigmoss\Internal\Payload;

final class BulkRedeliveryResult
{
    /**
     * @internal
     */
    public function __construct(
        public readonly int $queued,
        public readonly int $alreadyPending,
        /** Older events of objects that have a newer one. */
        public readonly int $skippedOutdated,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $p): self
    {
        return new self($p->int('queued'), $p->int('already_pending'), $p->int('skipped_outdated'));
    }
}
