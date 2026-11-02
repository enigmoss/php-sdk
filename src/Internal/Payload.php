<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

use Enigmoss\Exception\UnexpectedResponseException;

/**
 * Typed reader over a decoded JSON object. Every accessor checks the type and reports the full
 * path of a mismatch ("data.payer.amount"), so a contract change fails loudly and precisely.
 * Unknown fields are ignored.
 *
 * @internal
 */
final class Payload
{
    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(private readonly array $data, private readonly string $path = 'data') {}

    public static function from(mixed $value, string $path = 'data'): self
    {
        if (!\is_array($value)) {
            throw new UnexpectedResponseException(\sprintf('Expected "%s" to be an object, got %s.', $path, get_debug_type($value)));
        }

        return new self($value, $path);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data) && $this->data[$key] !== null;
    }

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;
        if (!\is_string($value)) {
            throw $this->mismatch($key, 'string', $value);
        }

        return $value;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;
        if ($value !== null && !\is_string($value)) {
            throw $this->mismatch($key, 'string|null', $value);
        }

        return $value;
    }

    public function int(string $key): int
    {
        $value = $this->data[$key] ?? null;
        if (!\is_int($value)) {
            throw $this->mismatch($key, 'int', $value);
        }

        return $value;
    }

    public function nullableInt(string $key): ?int
    {
        $value = $this->data[$key] ?? null;
        if ($value !== null && !\is_int($value)) {
            throw $this->mismatch($key, 'int|null', $value);
        }

        return $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->data[$key] ?? null;
        if (!\is_bool($value)) {
            throw $this->mismatch($key, 'bool', $value);
        }

        return $value;
    }

    /**
     * A decimal amount. Money is always a JSON string in the API; a number is rejected so a
     * float never sneaks into money handling.
     *
     * @return numeric-string
     */
    public function decimal(string $key): string
    {
        $value = $this->string($key);
        if (!is_numeric($value)) {
            throw $this->mismatch($key, 'decimal string', $value);
        }

        return $value;
    }

    /**
     * @return numeric-string|null
     */
    public function nullableDecimal(string $key): ?string
    {
        if (($this->data[$key] ?? null) === null) {
            return null;
        }

        return $this->decimal($key);
    }

    public function dateTime(string $key): \DateTimeImmutable
    {
        $value = $this->string($key);
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw $this->mismatch($key, 'ISO-8601 date-time', $value);
        }
    }

    public function nullableDateTime(string $key): ?\DateTimeImmutable
    {
        if (($this->data[$key] ?? null) === null) {
            return null;
        }

        return $this->dateTime($key);
    }

    /**
     * A string enum; values unknown to this SDK version map to $fallback (the enum's Unknown case).
     *
     * @template T of \BackedEnum
     *
     * @param class-string<T> $class
     * @param T               $fallback
     *
     * @return T
     */
    public function enum(string $key, string $class, \BackedEnum $fallback): \BackedEnum
    {
        $value = $this->string($key);

        return $class::tryFrom($value) ?? $fallback;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $class
     * @param T               $fallback
     *
     * @return T|null
     */
    public function nullableEnum(string $key, string $class, \BackedEnum $fallback): ?\BackedEnum
    {
        if (($this->data[$key] ?? null) === null) {
            return null;
        }

        return $this->enum($key, $class, $fallback);
    }

    public function object(string $key): self
    {
        return self::from($this->data[$key] ?? null, $this->path . '.' . $key);
    }

    public function nullableObject(string $key): ?self
    {
        if (($this->data[$key] ?? null) === null) {
            return null;
        }

        return $this->object($key);
    }

    /**
     * A JSON object with arbitrary content (metadata). An empty object decodes to [].
     *
     * @return array<array-key, mixed>
     */
    public function map(string $key): array
    {
        $value = $this->data[$key] ?? [];
        if (!\is_array($value)) {
            throw $this->mismatch($key, 'object', $value);
        }

        return $value;
    }

    /**
     * @return list<self>
     */
    public function objects(string $key): array
    {
        $value = $this->data[$key] ?? null;
        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->mismatch($key, 'list', $value);
        }

        $items = [];
        foreach ($value as $index => $item) {
            $items[] = self::from($item, $this->path . '.' . $key . '.' . $index);
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $value = $this->data[$key] ?? null;
        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->mismatch($key, 'list of strings', $value);
        }
        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw $this->mismatch($key, 'list of strings', $value);
            }
        }

        /** @var list<string> $value */
        return $value;
    }

    private function mismatch(string $key, string $expected, mixed $value): UnexpectedResponseException
    {
        return new UnexpectedResponseException(\sprintf(
            'Unexpected type of "%s.%s": expected %s, got %s.',
            $this->path,
            $key,
            $expected,
            get_debug_type($value),
        ));
    }
}
