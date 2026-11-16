<?php

declare(strict_types=1);

namespace Enigmoss\Http;

enum ClientEventType: string
{
    case RequestSending = 'request_sending';
    case ResponseReceived = 'response_received';
    case RetryScheduled = 'retry_scheduled';
    case RequestFailed = 'request_failed';

    /** The client moved to the API origin named by the fallback host (ClientEvent::$origin). */
    case OriginChanged = 'origin_changed';
}
