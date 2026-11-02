<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * The request failed validation (HTTP 422 validation.failed, or a missing/malformed Idempotency-Key).
 */
final class ValidationException extends ApiException
{
    /**
     * Violations of one field, e.g. violationsFor('amount').
     *
     * @return list<Violation>
     */
    public function violationsFor(string $field): array
    {
        return array_values(array_filter($this->violations, static fn(Violation $v): bool => $v->field === $field));
    }
}
