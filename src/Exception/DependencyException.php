<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * An upstream gateway or service failed (HTTP 502/503/504).
 */
final class DependencyException extends ApiException {}
