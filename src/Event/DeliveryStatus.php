<?php

declare(strict_types=1);

namespace Enigmoss\Event;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';
    /** A newer event of the same object made this one obsolete. */
    case Superseded = 'superseded';
    /** No callback URL was configured. */
    case NoDestination = 'no_destination';
    case Unknown = 'unknown';
}
