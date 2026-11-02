<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * An internal server error (HTTP 500). The outcome is unknown: reconcile before creating a new object.
 */
final class ServerException extends ApiException {}
