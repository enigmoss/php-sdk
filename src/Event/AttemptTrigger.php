<?php

declare(strict_types=1);

namespace Enigmoss\Event;

enum AttemptTrigger: string
{
    case Auto = 'auto';
    case Retry = 'retry';
    case ProxyRetry = 'proxy_retry';
    case Manual = 'manual';
    case Bulk = 'bulk';
    case Unknown = 'unknown';
}
