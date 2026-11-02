<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * A business rule rejected the request: insufficient funds, amount limits, unavailable method (HTTP 422, 415).
 */
final class BusinessRuleException extends ApiException {}
