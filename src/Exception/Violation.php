<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * One failed validation rule of a 422 validation.failed response.
 */
final class Violation
{
    /**
     * @param string|null          $field   Dot path of the field ("allowed_methods.0.currency_code"), null for the whole request.
     * @param string               $rule    Machine name of the rule ("required", "max", "custom"...).
     * @param string               $message Human-readable English text; do not parse it.
     * @param array<string, mixed> $params  Rule parameters, e.g. ["max" => "128"].
     */
    public function __construct(
        public readonly ?string $field,
        public readonly string $rule,
        public readonly string $message,
        public readonly array $params = [],
    ) {}
}
