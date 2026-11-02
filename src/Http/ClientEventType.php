<?php

declare(strict_types=1);

namespace Enigmoss\Http;

enum ClientEventType: string
{
    case RequestSending = 'request_sending';
    case ResponseReceived = 'response_received';
    case RetryScheduled = 'retry_scheduled';
    case RequestFailed = 'request_failed';
}
