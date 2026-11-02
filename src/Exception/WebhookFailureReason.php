<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

enum WebhookFailureReason: string
{
    case MissingHeader = 'missing_header';
    case ProjectMismatch = 'project_mismatch';
    case TimestampOutOfTolerance = 'timestamp_out_of_tolerance';
    case SignatureMismatch = 'signature_mismatch';
    case MalformedPayload = 'malformed_payload';
}
