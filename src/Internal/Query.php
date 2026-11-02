<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

/**
 * @internal
 */
final class Query
{
    /**
     * Converts to UTC first, then formats with a literal "Z" and no fractional seconds (the
     * events API rejects fractions).
     */
    public static function dateTime(?\DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public static function withoutNulls(array $values): array
    {
        return array_filter($values, static fn(mixed $value): bool => $value !== null);
    }
}
