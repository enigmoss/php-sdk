<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

/**
 * @internal
 */
enum IdempotencyMode
{
    case None;
    /** The endpoint accepts a key; the SDK sends one on every call so retries are safe. */
    case Supported;
    case Required;
}
