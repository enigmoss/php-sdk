<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * The object does not exist or belongs to another project; both look the same (HTTP 404).
 */
final class NotFoundException extends ApiException {}
