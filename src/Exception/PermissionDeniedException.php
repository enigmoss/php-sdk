<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * The key may not use this endpoint: permission disabled, IP not allowed, payouts forbidden (HTTP 403).
 */
class PermissionDeniedException extends ApiException {}
